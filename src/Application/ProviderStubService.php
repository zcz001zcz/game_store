<?php

declare(strict_types=1);

namespace GameStore\Application;

use GameStore\Core\Database\Database;
use GameStore\Core\Http\BadRequestException;
use GameStore\Core\Http\ConflictException;
use GameStore\Core\Http\NotFoundException;
use GameStore\Core\Log\JsonLogger;
use PDO;

final class ProviderStubService
{
    private const MODES = [
        'random',
        'success',
        'fail_before_issue',
        'timeout_after_issue',
        'out_of_stock',
        'error_after_issue_once',
        'duplicate_code_once',
        'foreign_code_once',
        'crash_after_issue_once',
    ];

    public function __construct(
        private readonly Database $database,
        private readonly JsonLogger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function issue(string $provider, array $payload): array
    {
        $provider = $this->normalizeProvider($provider);
        $requestId = isset($payload['request_id']) && is_string($payload['request_id'])
            ? trim($payload['request_id'])
            : '';
        $sku = isset($payload['sku']) && is_string($payload['sku']) ? strtoupper(trim($payload['sku'])) : '';
        $orderId = isset($payload['order_id']) && is_string($payload['order_id']) ? trim($payload['order_id']) : '';

        if (!preg_match('/^req_[A-Za-z0-9_-]{3,90}$/', $requestId)) {
            throw new BadRequestException('Invalid request_id');
        }

        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,99}$/', $sku)) {
            throw new BadRequestException('Invalid SKU');
        }

        if (!preg_match('/^ord_[A-Za-z0-9_-]{3,70}$/', $orderId)) {
            throw new BadRequestException('Invalid order_id');
        }

        $result = $this->database->transaction(function (PDO $pdo) use ($provider, $requestId, $sku, $orderId): array {
            $lock = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
            $lock->execute(['lock_key' => 'provider:' . $provider . ':' . $requestId]);
            $existing = $this->findIssue($pdo, $provider, $requestId);

            if ($existing !== null) {
                if ((string) $existing['sku'] !== $sku || (string) $existing['order_public_id'] !== $orderId) {
                    return [
                        'status' => 409,
                        'body' => ['status' => 'error', 'reason' => 'idempotency_conflict'],
                        'timeout_ms' => 0,
                    ];
                }

                return ['status' => 200, 'body' => $this->successBody($existing), 'timeout_ms' => 0];
            }

            $settingsStatement = $pdo->prepare('SELECT * FROM stub_provider_settings WHERE provider = :provider FOR UPDATE');
            $settingsStatement->execute(['provider' => $provider]);
            $settings = $settingsStatement->fetch();

            if (!is_array($settings)) {
                throw new \LogicException('Provider settings are missing');
            }

            $mode = (string) $settings['mode'];

            if ($mode === 'random') {
                $roll = random_int(1, 10_000) / 10_000;
                $failureRate = (float) $settings['failure_rate'];
                $timeoutRate = (float) $settings['timeout_rate'];
                $mode = $roll <= $failureRate
                    ? 'fail_before_issue'
                    : ($roll <= $failureRate + $timeoutRate ? 'timeout_after_issue' : 'success');
            }

            if ($mode === 'fail_before_issue') {
                return [
                    'status' => 503,
                    'body' => ['status' => 'error', 'reason' => 'unavailable_before_issue'],
                    'timeout_ms' => 0,
                ];
            }

            if ($mode === 'out_of_stock') {
                return [
                    'status' => 409,
                    'body' => ['status' => 'error', 'reason' => 'out_of_stock'],
                    'timeout_ms' => 0,
                ];
            }

            if ($mode === 'duplicate_code_once') {
                $duplicate = $pdo->prepare(
                    'SELECT pi.code FROM provider_issues pi '
                    . 'JOIN fulfillments f ON f.provider = pi.provider AND f.request_id = pi.request_id '
                    . 'WHERE pi.provider = :provider AND pi.actual_sku = :sku '
                    . 'ORDER BY pi.id ASC LIMIT 1'
                );
                $duplicate->execute(['provider' => $provider, 'sku' => $sku]);
                $code = $duplicate->fetchColumn();

                if (is_string($code)) {
                    $issue = $this->insertIssue(
                        $pdo,
                        $provider,
                        $requestId,
                        $orderId,
                        $sku,
                        $sku,
                        null,
                        $code,
                        'duplicate_code',
                    );
                    $this->consumeOneShotMode($pdo, $provider);

                    return ['status' => 200, 'body' => $this->successBody($issue), 'timeout_ms' => 0];
                }

                $this->consumeOneShotMode($pdo, $provider);
                $mode = 'success';
            }

            if ($mode === 'foreign_code_once') {
                $stockStatement = $pdo->prepare(
                    'SELECT id, sku, code FROM provider_stock '
                    . 'WHERE provider = :provider AND sku <> :sku AND issued_at IS NULL '
                    . 'ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED'
                );
                $stockStatement->execute(['provider' => $provider, 'sku' => $sku]);
                $stock = $stockStatement->fetch();

                if (is_array($stock)) {
                    $issue = $this->insertIssue(
                        $pdo,
                        $provider,
                        $requestId,
                        $orderId,
                        $sku,
                        (string) $stock['sku'],
                        (int) $stock['id'],
                        (string) $stock['code'],
                        'foreign_code',
                    );
                    $this->markStockIssued($pdo, (int) $stock['id']);
                    $this->consumeOneShotMode($pdo, $provider);

                    return ['status' => 200, 'body' => $this->successBody($issue), 'timeout_ms' => 0];
                }

                $this->consumeOneShotMode($pdo, $provider);
                $mode = 'success';
            }

            $stockStatement = $pdo->prepare(
                'SELECT id, sku, code FROM provider_stock '
                . 'WHERE provider = :provider AND sku = :sku AND issued_at IS NULL '
                . 'ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $stockStatement->execute(['provider' => $provider, 'sku' => $sku]);
            $stock = $stockStatement->fetch();

            if (!is_array($stock)) {
                return [
                    'status' => 409,
                    'body' => ['status' => 'error', 'reason' => 'out_of_stock'],
                    'timeout_ms' => 0,
                ];
            }

            $issue = $this->insertIssue(
                $pdo,
                $provider,
                $requestId,
                $orderId,
                $sku,
                (string) $stock['sku'],
                (int) $stock['id'],
                (string) $stock['code'],
                'honest',
            );
            $this->markStockIssued($pdo, (int) $stock['id']);

            if (in_array($mode, ['error_after_issue_once', 'crash_after_issue_once'], true)) {
                $this->consumeOneShotMode($pdo, $provider);
            }

            if ($mode === 'error_after_issue_once') {
                return [
                    'status' => 500,
                    'body' => ['status' => 'error', 'reason' => 'error_after_issue'],
                    'timeout_ms' => 0,
                ];
            }

            $body = $this->successBody($issue);

            if ($mode === 'crash_after_issue_once') {
                $body['test_fault'] = 'crash_worker_after_audit';
            }

            return [
                'status' => 200,
                'body' => $body,
                'timeout_ms' => $mode === 'timeout_after_issue' ? (int) $settings['timeout_ms'] : 0,
            ];
        });

        $this->logger->info('provider_stub_issue_processed', [
            'provider' => $provider,
            'request_id' => $requestId,
            'order_id' => $orderId,
            'http_status' => $result['status'],
            'timeout_after_issue' => $result['timeout_ms'] > 0,
        ]);

        if ($result['timeout_ms'] > 0) {
            usleep($result['timeout_ms'] * 1000);
        }

        return ['status' => $result['status'], 'body' => $result['body']];
    }

    /** @return array<string, mixed> */
    public function audit(string $provider, string $requestId): array
    {
        $provider = $this->normalizeProvider($provider);

        if (!preg_match('/^req_[A-Za-z0-9_-]{3,90}$/', $requestId)) {
            throw new BadRequestException('Invalid request_id');
        }

        $issue = $this->findIssue($this->database->connection(), $provider, $requestId);

        if ($issue === null) {
            throw new NotFoundException('Provider issue not found');
        }

        return [
            'status' => 'ok',
            'request_id' => (string) $issue['request_id'],
            'order_id' => (string) $issue['order_public_id'],
            'requested_sku' => (string) $issue['sku'],
            'actual_sku' => (string) $issue['actual_sku'],
            'code' => (string) $issue['code'],
        ];
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    public function configure(string $provider, array $settings): array
    {
        $provider = $this->normalizeProvider($provider);
        $mode = isset($settings['mode']) && is_string($settings['mode']) ? trim($settings['mode']) : 'random';
        $failureRate = $settings['failure_rate'] ?? 0;
        $timeoutRate = $settings['timeout_rate'] ?? 0;
        $timeoutMs = $settings['timeout_ms'] ?? 1500;
        $rateLimit = $settings['rate_limit'] ?? 60;
        $rateWindow = $settings['rate_window_seconds'] ?? 60;

        if (!in_array($mode, self::MODES, true)) {
            throw new BadRequestException('Invalid provider mode', ['allowed' => self::MODES]);
        }

        if (!is_numeric($failureRate) || (float) $failureRate < 0 || (float) $failureRate > 1) {
            throw new BadRequestException('failure_rate must be between 0 and 1');
        }

        if (!is_numeric($timeoutRate) || (float) $timeoutRate < 0 || (float) $timeoutRate > 1) {
            throw new BadRequestException('timeout_rate must be between 0 and 1');
        }

        if (!is_int($timeoutMs) || $timeoutMs < 1 || $timeoutMs > 60_000) {
            throw new BadRequestException('timeout_ms must be an integer between 1 and 60000');
        }

        if (!is_int($rateLimit) || $rateLimit < 1 || $rateLimit > 10_000) {
            throw new BadRequestException('rate_limit must be an integer between 1 and 10000');
        }

        if (!is_int($rateWindow) || $rateWindow < 1 || $rateWindow > 3600) {
            throw new BadRequestException('rate_window_seconds must be an integer between 1 and 3600');
        }

        if ((float) $failureRate + (float) $timeoutRate > 1) {
            throw new BadRequestException('failure_rate + timeout_rate cannot exceed 1');
        }

        return $this->database->transaction(function (PDO $pdo) use (
            $provider,
            $mode,
            $failureRate,
            $timeoutRate,
            $timeoutMs,
            $rateLimit,
            $rateWindow,
        ): array {
            $statement = $pdo->prepare(
                'UPDATE stub_provider_settings '
                . 'SET mode = :mode, failure_rate = :failure_rate, timeout_rate = :timeout_rate, '
                . 'timeout_ms = :timeout_ms, rate_limit = :rate_limit, '
                . 'rate_window_seconds = :rate_window, updated_at = NOW() '
                . 'WHERE provider = :provider RETURNING *'
            );
            $statement->execute([
                'mode' => $mode,
                'failure_rate' => (float) $failureRate,
                'timeout_rate' => (float) $timeoutRate,
                'timeout_ms' => $timeoutMs,
                'rate_limit' => $rateLimit,
                'rate_window' => $rateWindow,
                'provider' => $provider,
            ]);
            $updated = $statement->fetch();

            if (!is_array($updated)) {
                throw new ConflictException('Provider settings could not be updated');
            }

            $clear = $pdo->prepare('DELETE FROM provider_request_log WHERE provider = :provider');
            $clear->execute(['provider' => $provider]);

            return $this->presentSettings($updated);
        });
    }

    /** @return array<string, mixed> */
    public function getSettings(string $provider): array
    {
        $provider = $this->normalizeProvider($provider);
        $statement = $this->database->connection()->prepare(
            'SELECT s.*, '
            . '(SELECT COUNT(*) FROM provider_stock ps WHERE ps.provider = s.provider AND ps.issued_at IS NULL) AS available_codes '
            . 'FROM stub_provider_settings s WHERE s.provider = :provider'
        );
        $statement->execute(['provider' => $provider]);
        $settings = $statement->fetch();

        if (!is_array($settings)) {
            throw new ConflictException('Provider settings are missing');
        }

        return $this->presentSettings($settings);
    }

    /** @return array<string, mixed>|null */
    private function findIssue(PDO $pdo, string $provider, string $requestId): ?array
    {
        $statement = $pdo->prepare(
            'SELECT request_id, order_public_id, sku, actual_sku, code, issue_kind '
            . 'FROM provider_issues WHERE provider = :provider AND request_id = :request_id'
        );
        $statement->execute(['provider' => $provider, 'request_id' => $requestId]);
        $issue = $statement->fetch();

        return is_array($issue) ? $issue : null;
    }

    /** @return array<string, mixed> */
    private function insertIssue(
        PDO $pdo,
        string $provider,
        string $requestId,
        string $orderId,
        string $requestedSku,
        string $actualSku,
        ?int $stockId,
        string $code,
        string $kind,
    ): array {
        $statement = $pdo->prepare(
            'INSERT INTO provider_issues '
            . '(provider, request_id, order_public_id, sku, actual_sku, stock_id, code, issue_kind) '
            . 'VALUES (:provider, :request_id, :order_id, :sku, :actual_sku, :stock_id, :code, :kind) '
            . 'RETURNING request_id, order_public_id, sku, actual_sku, code, issue_kind'
        );
        $statement->execute([
            'provider' => $provider,
            'request_id' => $requestId,
            'order_id' => $orderId,
            'sku' => $requestedSku,
            'actual_sku' => $actualSku,
            'stock_id' => $stockId,
            'code' => $code,
            'kind' => $kind,
        ]);
        $issue = $statement->fetch();

        if (!is_array($issue)) {
            throw new \LogicException('Provider issue could not be stored');
        }

        return $issue;
    }

    private function markStockIssued(PDO $pdo, int $stockId): void
    {
        $statement = $pdo->prepare('UPDATE provider_stock SET issued_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $stockId]);
    }

    private function consumeOneShotMode(PDO $pdo, string $provider): void
    {
        $statement = $pdo->prepare(
            "UPDATE stub_provider_settings SET mode = 'success', updated_at = NOW() WHERE provider = :provider"
        );
        $statement->execute(['provider' => $provider]);
    }

    /** @param array<string, mixed> $issue @return array<string, mixed> */
    private function successBody(array $issue): array
    {
        return [
            'status' => 'ok',
            'request_id' => (string) $issue['request_id'],
            'code' => (string) $issue['code'],
        ];
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtoupper(trim($provider));

        if (!in_array($provider, ['A', 'B'], true)) {
            throw new BadRequestException('Provider must be A or B');
        }

        return $provider;
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function presentSettings(array $settings): array
    {
        return [
            'provider' => trim((string) $settings['provider']),
            'mode' => $settings['mode'],
            'failure_rate' => (float) $settings['failure_rate'],
            'timeout_rate' => (float) $settings['timeout_rate'],
            'timeout_ms' => (int) $settings['timeout_ms'],
            'rate_limit' => (int) $settings['rate_limit'],
            'rate_window_seconds' => (int) $settings['rate_window_seconds'],
            'available_codes' => isset($settings['available_codes']) ? (int) $settings['available_codes'] : null,
            'updated_at' => $settings['updated_at'],
        ];
    }
}
