<?php

declare(strict_types=1);

namespace GameStore\Tests\IntegrationStage2;

use DateTimeImmutable;
use GameStore\Core\Database\ConnectionFactory;
use GameStore\Support\Env;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';

final class Stage2Failure extends RuntimeException
{
}

final class Stage2Suite
{
    private readonly string $runId;
    /** @var array<string, string> */
    private readonly array $skus;
    private int $passed = 0;
    private int $failed = 0;
    private string $partialOrderId = '';
    private string $partialCreatedAt = '';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $baseUrl,
    ) {
        $this->runId = gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
        $suffix = strtoupper($this->runId);
        $this->skus = [
            'cheap' => 'ITEST-STAGE2-CHEAP-' . $suffix,
            'expensive' => 'ITEST-STAGE2-EXPENSIVE-' . $suffix,
            'dishonest' => 'ITEST-STAGE2-DISHONEST-' . $suffix,
            'foreign' => 'ITEST-STAGE2-FOREIGN-' . $suffix,
            'crash' => 'ITEST-STAGE2-CRASH-' . $suffix,
            'burst' => 'ITEST-STAGE2-BURST-' . $suffix,
        ];
    }

    public function run(): int
    {
        $started = microtime(true);
        fwrite(STDOUT, "Game Store stage-2 integration suite\n");
        fwrite(STDOUT, sprintf("Run: %s\n\n", $this->runId));

        try {
            $this->waitForApi();
            $this->cleanPreviousRuns();
            $this->provision();
            $cases = [
                ['Multi-item partial fulfillment, refund and idempotency', fn (): string => $this->partialOrder()],
                ['Untrusted provider: duplicate, foreign and error-after-issue', fn (): string => $this->dishonestProvider()],
                ['Crash after provider issue and automatic restart recovery', fn (): string => $this->crashRecovery()],
                ['Burst queue, strict provider rate limit and payment admission', fn (): string => $this->burstAndRateLimit()],
                ['Append-only point-in-time history and period totals', fn (): string => $this->history()],
            ];

            foreach ($cases as [$name, $case]) {
                if (!$this->runCase($name, $case)) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            $this->failed++;
            fwrite(STDERR, '[FAIL] Suite setup: ' . $exception->getMessage() . "\n");
        } finally {
            $this->restoreProviders();
        }

        fwrite(STDOUT, sprintf(
            "\nResult: %d passed, %d failed (%.2fs)\n",
            $this->passed,
            $this->failed,
            microtime(true) - $started,
        ));
        fwrite(STDOUT, "Current run data is preserved for SQL inspection and is removed by the next integration run.\n");

        return $this->failed === 0 ? 0 : 1;
    }

    /** @param callable(): string $case */
    private function runCase(string $name, callable $case): bool
    {
        $started = microtime(true);

        try {
            $detail = $case();
            $this->passed++;
            fwrite(STDOUT, sprintf(
                "[PASS] %s (%.2fs; %s)\n",
                $name,
                microtime(true) - $started,
                $detail,
            ));

            return true;
        } catch (Throwable $exception) {
            $this->failed++;
            fwrite(STDERR, sprintf(
                "[FAIL] %s (%.2fs)\n       %s\n",
                $name,
                microtime(true) - $started,
                $exception->getMessage(),
            ));

            return false;
        }
    }

    private function partialOrder(): string
    {
        $this->configure('A', 'success');
        $this->configure('B', 'fail_before_issue');
        $orderId = $this->orderId('partial');
        $response = $this->request('POST', '/api/v1/orders', [
            'order_id' => $orderId,
            'items' => [
                ['sku' => $this->skus['cheap'], 'provider' => 'A'],
                ['sku' => $this->skus['expensive'], 'provider' => 'B'],
            ],
        ]);
        $this->same(201, $response['status'], 'multi-item create status');
        $replay = $this->request('POST', '/api/v1/orders', [
            'order_id' => $orderId,
            'items' => [
                ['sku' => $this->skus['cheap'], 'provider' => 'A'],
                ['sku' => $this->skus['expensive'], 'provider' => 'B'],
            ],
        ]);
        $this->same(200, $replay['status'], 'same basket idempotent replay status');
        $this->same(true, $replay['json']['meta']['idempotent_replay'] ?? null, 'same basket replay marker');
        $conflict = $this->request('POST', '/api/v1/orders', [
            'order_id' => $orderId,
            'items' => [['sku' => $this->skus['cheap'], 'provider' => 'B']],
        ]);
        $this->same(409, $conflict['status'], 'different basket idempotency conflict');
        $this->partialOrderId = $orderId;
        $this->partialCreatedAt = (string) $this->value(
            'SELECT recorded_at FROM order_events WHERE order_public_id = :id AND event_type = :type',
            ['id' => $orderId, 'type' => 'order.created'],
        );
        usleep(20_000);
        $eventId = $this->eventId('partial');
        $this->pay($eventId, $orderId, 300);
        $order = $this->waitForStatus($orderId, 'partially_refunded');
        $items = $order['items'] ?? [];
        $this->same(2, count($items), 'order item count');
        $this->same('delivered', $items[0]['status'] ?? null, 'first item status');
        $this->same('refunded', $items[1]['status'] ?? null, 'second item status');
        $money = $order['money'] ?? [];
        $this->same(30_000, $money['paid_minor'] ?? null, 'captured amount');
        $this->same(10_000, $money['delivered_minor'] ?? null, 'delivered amount');
        $this->same(20_000, $money['refunded_minor'] ?? null, 'refunded amount');
        $this->same(0, $money['outstanding_minor'] ?? null, 'outstanding amount');
        $this->same(true, $money['terminal_equation_holds'] ?? null, 'terminal money equation');
        $this->same(1, $this->countOrderRows('fulfillments', $orderId), 'one fulfillment');
        $this->same(1, $this->countOrderRows('refunds', $orderId), 'one refund');
        $this->same(2, $this->countOrderRows('ledger_entries', $orderId), 'capture plus refund');

        // Replays of both the payment and recovery steps are no-ops.
        $this->pay($eventId, $orderId, 300);
        $recovery = $this->request('POST', '/api/v1/ops/recovery', ['order_id' => $orderId]);
        $this->same(200, $recovery['status'], 'terminal recovery status');
        usleep(300_000);
        $this->same(1, $this->countOrderRows('fulfillments', $orderId), 'replay fulfillment count');
        $this->same(1, $this->countOrderRows('refunds', $orderId), 'replay refund count');

        return 'paid 30000 = delivered 10000 + refunded 20000; all replays stayed single-effect';
    }

    private function dishonestProvider(): string
    {
        $this->configure('A', 'success');
        $victim = $this->orderId('victim');
        $this->createSingleProviderOrder($victim, $this->skus['dishonest'], 'A');
        $this->pay($this->eventId('victim'), $victim, 150);
        $victimOrder = $this->waitForStatus($victim, 'delivered');
        $victimCode = (string) ($victimOrder['items'][0]['delivery']['code'] ?? '');

        $this->configure('A', 'duplicate_code_once');
        $duplicate = $this->orderId('duplicate');
        $this->createSingleProviderOrder($duplicate, $this->skus['dishonest'], 'A');
        $this->pay($this->eventId('duplicate'), $duplicate, 150);
        $duplicateOrder = $this->waitForStatus($duplicate, 'delivered');
        $replacementCode = (string) ($duplicateOrder['items'][0]['delivery']['code'] ?? '');
        $this->true($victimCode !== '' && $replacementCode !== '' && $victimCode !== $replacementCode, 'duplicate code was not delivered twice');
        $this->same(2, $this->providerIssueCount($duplicate), 'duplicate claim plus replacement');
        $this->same(1, $this->count(
            "SELECT COUNT(*) FROM provider_discrepancies pd JOIN orders o ON o.id = pd.order_id
             WHERE o.public_id = :id AND pd.kind = 'duplicate_code' AND pd.status = 'resolved'",
            ['id' => $duplicate],
        ), 'duplicate discrepancy auto-resolved');

        $this->configure('A', 'foreign_code_once');
        $foreign = $this->orderId('foreign');
        $this->createSingleProviderOrder($foreign, $this->skus['foreign'], 'A');
        $this->pay($this->eventId('foreign'), $foreign, 175);
        $this->waitForStatus($foreign, 'delivered');
        $this->same(2, $this->providerIssueCount($foreign), 'foreign claim plus replacement');
        $this->same(1, $this->count(
            "SELECT COUNT(*) FROM provider_discrepancies pd JOIN orders o ON o.id = pd.order_id
             WHERE o.public_id = :id AND pd.kind = 'foreign_sku' AND pd.status = 'resolved'",
            ['id' => $foreign],
        ), 'foreign-code discrepancy auto-resolved');

        $this->configure('A', 'error_after_issue_once');
        $errored = $this->orderId('error_after_issue');
        $this->createSingleProviderOrder($errored, $this->skus['dishonest'], 'A');
        $this->pay($this->eventId('error_after_issue'), $errored, 150);
        $this->waitForStatus($errored, 'delivered');
        $this->same(1, $this->providerIssueCount($errored), 'error-after-issue consumed one code');
        $this->same(1, $this->countOrderRows('fulfillments', $errored), 'error-after-issue delivered once');

        return 'bad claims quarantined and replaced; error-after-issue reconciled to one provider issue';
    }

    private function crashRecovery(): string
    {
        $this->configure('A', 'crash_after_issue_once');
        $orderId = $this->orderId('crash');
        $this->createSingleProviderOrder($orderId, $this->skus['crash'], 'A');
        $this->pay($this->eventId('crash'), $orderId, 125);
        $this->waitForStatus($orderId, 'delivered', 35);
        $this->same(1, $this->providerIssueCount($orderId), 'crash boundary provider issue count');
        $this->same(1, $this->countOrderRows('fulfillments', $orderId), 'crash boundary fulfillment count');
        $attempts = (int) $this->value(
            'SELECT attempts FROM jobs WHERE aggregate_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $orderId],
        );
        $this->true($attempts >= 2, 'stale processing job was reserved after worker restart');

        return sprintf('worker exited after external issue; attempt %d reused the same request_id and code', $attempts);
    }

    private function burstAndRateLimit(): string
    {
        $this->configure('A', 'success', 3, 2);
        $unpaid = $this->orderId('burst_unpaid');
        $this->createSingleProviderOrder($unpaid, $this->skus['burst'], 'A');
        $this->same(0, $this->count(
            'SELECT COUNT(*) FROM jobs WHERE aggregate_id = :id',
            ['id' => $unpaid],
        ), 'unpaid order is not admitted to delivery queue');
        $orders = [];

        for ($index = 1; $index <= 9; $index++) {
            $orderId = $this->orderId('burst_' . $index);
            $orders[] = $orderId;
            $this->createSingleProviderOrder($orderId, $this->skus['burst'], 'A');
            $this->pay($this->eventId('burst_' . $index), $orderId, 50);
        }

        $progress = $this->request('GET', '/api/v1/ops/queue-progress', null);
        $this->same(200, $progress['status'], 'queue progress status');
        $this->true(isset($progress['json']['data']['queued_orders']), 'queue progress exposes queued orders');
        $this->true(
            (int) ($progress['json']['data']['queued_orders'] ?? 0)
                + (int) ($progress['json']['data']['processing_orders'] ?? 0) > 0,
            'queue progress observes the throttled backlog',
        );

        foreach ($orders as $orderId) {
            $this->waitForStatus($orderId, 'delivered', 35);
        }

        $maxWindow = (int) $this->value(
            "SELECT COALESCE(MAX(request_count), 0) FROM (
                 SELECT COUNT(b.id) AS request_count
                 FROM provider_request_log a
                 JOIN provider_request_log b
                   ON b.provider = a.provider
                  AND b.requested_at >= a.requested_at
                  AND b.requested_at < a.requested_at + INTERVAL '2 seconds'
                 WHERE a.provider = 'A'
                 GROUP BY a.id
             ) windows",
        );
        $this->true($maxWindow <= 3, 'provider sliding-window limit was not exceeded');
        $this->same(9, $this->count(
            "SELECT COUNT(*) FROM provider_request_log WHERE provider = 'A'",
        ), 'every paid order made exactly one permitted provider request');
        $this->same(0, $this->providerIssueCount($unpaid), 'unpaid order made no provider request');
        $finalProgress = $this->request('GET', '/api/v1/ops/queue-progress', null);
        $this->true(
            (int) ($finalProgress['json']['data']['delivered_items'] ?? 0) >= 9,
            'progress exposes delivered items',
        );

        return sprintf('9 paid orders drained safely; maximum requests in any 2s window: %d/3', $maxWindow);
    }

    private function history(): string
    {
        $atCreated = rawurlencode((new DateTimeImmutable($this->partialCreatedAt))->format('Y-m-d\\TH:i:s.uP'));
        $state = $this->request(
            'GET',
            '/api/v1/orders/' . rawurlencode($this->partialOrderId) . '/state-at?at=' . $atCreated,
            null,
        );
        $this->same(200, $state['status'], 'point-in-time endpoint status');
        $this->same('created', $state['json']['data']['state']['status'] ?? null, 'state before payment');

        $summary = $this->request(
            'GET',
            '/api/v1/ops/history-summary?from=2000-01-01T00%3A00%3A00Z'
            . '&to=2100-01-01T00%3A00%3A00Z&order_id=' . rawurlencode($this->partialOrderId),
            null,
        );
        $this->same(200, $summary['status'], 'history summary status');
        $data = $summary['json']['data'] ?? [];
        $this->same(30_000, $data['captured_minor'] ?? null, 'history captured total');
        $this->same(10_000, $data['delivered_minor'] ?? null, 'history delivered total');
        $this->same(20_000, $data['refunded_minor'] ?? null, 'history refunded total');
        $this->same(true, $data['terminal_equation_holds'] ?? null, 'history period equation');

        $blocked = false;
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'UPDATE order_events SET event_type = :type WHERE order_public_id = :id'
            );
            $statement->execute(['type' => 'tampered', 'id' => $this->partialOrderId]);
            $this->pdo->commit();
        } catch (PDOException) {
            $blocked = true;

            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }

        $this->true($blocked, 'append-only trigger rejects history updates');

        return 'historical snapshot restored, immutable log rejected UPDATE, period equation balanced';
    }

    private function provision(): void
    {
        $products = [
            [$this->skus['cheap'], 'Stage 2 cheap item', 10_000],
            [$this->skus['expensive'], 'Stage 2 expensive item', 20_000],
            [$this->skus['dishonest'], 'Stage 2 dishonest-provider item', 15_000],
            [$this->skus['foreign'], 'Stage 2 foreign-code item', 17_500],
            [$this->skus['crash'], 'Stage 2 crash item', 12_500],
            [$this->skus['burst'], 'Stage 2 burst item', 5_000],
        ];
        $product = $this->pdo->prepare(
            "INSERT INTO products (sku, name, type, price_minor, currency)
             VALUES (:sku, :name, 'key', :price, 'RUB')"
        );

        foreach ($products as [$sku, $name, $price]) {
            $product->execute(['sku' => $sku, 'name' => $name, 'price' => $price]);
        }

        $stock = $this->pdo->prepare(
            'INSERT INTO provider_stock (provider, sku, code) VALUES (:provider, :sku, :code)'
        );
        $prefix = 'ITEST-' . strtoupper($this->runId) . '-S2-';
        $counts = [
            'cheap' => ['A' => 2, 'B' => 0],
            'expensive' => ['A' => 0, 'B' => 1],
            'dishonest' => ['A' => 8, 'B' => 0],
            'foreign' => ['A' => 4, 'B' => 0],
            'crash' => ['A' => 2, 'B' => 0],
            'burst' => ['A' => 12, 'B' => 0],
        ];

        foreach ($counts as $key => $providers) {
            foreach ($providers as $provider => $count) {
                for ($index = 1; $index <= $count; $index++) {
                    $stock->execute([
                        'provider' => $provider,
                        'sku' => $this->skus[$key],
                        'code' => $prefix . strtoupper($key) . '-' . $provider . '-' . $index,
                    ]);
                }
            }
        }
    }

    private function cleanPreviousRuns(): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec(
                "UPDATE provider_stock ps SET issued_at = NULL FROM provider_issues pi
                 WHERE ps.id = pi.stock_id AND LEFT(pi.order_public_id, 7) = 'ord_it_'"
            );
            $this->pdo->exec(
                "DELETE FROM provider_discrepancies
                 WHERE order_id IN (SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_')"
            );
            $this->pdo->exec(
                "DELETE FROM quarantined_codes WHERE first_request_id IN (
                     SELECT request_id FROM provider_issues WHERE LEFT(order_public_id, 7) = 'ord_it_'
                 )"
            );
            $this->pdo->exec(
                "DELETE FROM fulfillments
                 WHERE order_id IN (SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_')"
            );
            $this->pdo->exec(
                "DELETE FROM delivery_attempts
                 WHERE order_id IN (SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_')"
            );
            $this->pdo->exec("DELETE FROM provider_issues WHERE LEFT(order_public_id, 7) = 'ord_it_'");
            $this->pdo->exec(
                "DELETE FROM refunds
                 WHERE order_id IN (SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_')"
            );
            $this->pdo->exec(
                "DELETE FROM ledger_entries
                 WHERE order_id IN (SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_')"
            );
            $this->pdo->exec("DELETE FROM payment_events WHERE LEFT(order_public_id, 7) = 'ord_it_'");
            $this->pdo->exec("DELETE FROM jobs WHERE LEFT(aggregate_id, 7) = 'ord_it_'");
            $this->pdo->exec(
                "DELETE FROM order_items
                 WHERE order_id IN (SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_')"
            );
            $this->pdo->exec("DELETE FROM orders WHERE LEFT(public_id, 7) = 'ord_it_'");
            $this->pdo->exec("DELETE FROM provider_stock WHERE LEFT(code, 6) = 'ITEST-'");
            $this->pdo->exec("DELETE FROM products WHERE LEFT(sku, 6) = 'ITEST-'");
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function createSingleProviderOrder(string $orderId, string $sku, string $provider): void
    {
        $response = $this->request('POST', '/api/v1/orders', [
            'order_id' => $orderId,
            'items' => [['sku' => $sku, 'provider' => $provider]],
        ]);
        $this->same(201, $response['status'], 'create item order status');
    }

    private function pay(string $eventId, string $orderId, int|float $amount): void
    {
        $response = $this->request('POST', '/api/v1/webhooks/payment', [
            'event_id' => $eventId,
            'order_id' => $orderId,
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $this->same(200, $response['status'], 'payment webhook status');
    }

    /** @return array<string, mixed> */
    private function waitForStatus(string $orderId, string $status, int $timeout = 25): array
    {
        $deadline = microtime(true) + $timeout;
        $last = 'unknown';

        do {
            $response = $this->request('GET', '/api/v1/orders/' . rawurlencode($orderId), null);
            $last = (string) ($response['json']['data']['status'] ?? 'missing');

            if ($response['status'] === 200 && $last === $status) {
                return $response['json']['data'];
            }

            usleep(200_000);
        } while (microtime(true) < $deadline);

        throw new Stage2Failure(sprintf('%s did not reach %s; latest status: %s', $orderId, $status, $last));
    }

    private function configure(string $provider, string $mode, int $limit = 60, int $window = 60): void
    {
        $response = $this->request('PUT', '/api/v1/dev/providers/' . $provider, [
            'mode' => $mode,
            'failure_rate' => 0,
            'timeout_rate' => 0,
            'timeout_ms' => 1500,
            'rate_limit' => $limit,
            'rate_window_seconds' => $window,
        ]);
        $this->same(200, $response['status'], 'provider configuration');
    }

    private function restoreProviders(): void
    {
        foreach (['A' => [0.1, 0.1], 'B' => [0.05, 0.05]] as $provider => [$failure, $timeout]) {
            try {
                $this->request('PUT', '/api/v1/dev/providers/' . $provider, [
                    'mode' => 'random',
                    'failure_rate' => $failure,
                    'timeout_rate' => $timeout,
                    'timeout_ms' => 1500,
                    'rate_limit' => 60,
                    'rate_window_seconds' => 60,
                ]);
            } catch (Throwable) {
            }
        }
    }

    private function waitForApi(): void
    {
        $deadline = microtime(true) + 30;

        do {
            try {
                $response = $this->request('GET', '/health', null);

                if ($response['status'] === 200) {
                    return;
                }
            } catch (Throwable) {
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        throw new Stage2Failure('API did not become healthy');
    }

    private function providerIssueCount(string $orderId): int
    {
        return $this->count('SELECT COUNT(*) FROM provider_issues WHERE order_public_id = :id', ['id' => $orderId]);
    }

    private function countOrderRows(string $table, string $orderId): int
    {
        if (!in_array($table, ['fulfillments', 'refunds', 'ledger_entries'], true)) {
            throw new Stage2Failure('Unsupported assertion table');
        }

        return $this->count(
            sprintf('SELECT COUNT(*) FROM %s t JOIN orders o ON o.id = t.order_id WHERE o.public_id = :id', $table),
            ['id' => $orderId],
        );
    }

    /** @param array<string, int|string> $parameters */
    private function count(string $sql, array $parameters = []): int
    {
        return (int) $this->value($sql, $parameters);
    }

    /** @param array<string, int|string> $parameters */
    private function value(string $sql, array $parameters = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $value = $statement->fetchColumn();

        if ($value === false) {
            throw new Stage2Failure('Database assertion returned no value');
        }

        return $value;
    }

    private function orderId(string $scenario): string
    {
        return 'ord_it_2_' . $scenario . '_' . $this->runId;
    }

    private function eventId(string $scenario): string
    {
        return 'evt_it_2_' . $scenario . '_' . $this->runId;
    }

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new Stage2Failure(sprintf(
                '%s: expected %s, got %s',
                $message,
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }

    private function true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new Stage2Failure($message);
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, json: array<string, mixed>}
     */
    private function request(string $method, string $path, ?array $payload): array
    {
        $curl = curl_init($this->baseUrl . $path);

        if ($curl === false) {
            throw new Stage2Failure('Cannot initialize cURL');
        }

        $headers = ['Accept: application/json', 'Connection: close'];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 2_000,
            CURLOPT_TIMEOUT_MS => 30_000,
        ];

        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);

        if ($raw === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new Stage2Failure($method . ' ' . $path . ' failed: ' . $error);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        try {
            $json = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new Stage2Failure('Invalid JSON response: ' . $raw, 0, $exception);
        }

        if (!is_array($json)) {
            throw new Stage2Failure('Response is not a JSON object');
        }

        return ['status' => $status, 'json' => $json];
    }
}

try {
    $environment = strtolower(Env::string('APP_ENV', 'dev'));

    if (!in_array($environment, ['dev', 'development', 'local', 'test', 'testing'], true)) {
        throw new Stage2Failure('Stage-2 runner refuses to mutate APP_ENV=' . $environment);
    }

    $suite = new Stage2Suite(
        ConnectionFactory::create(),
        rtrim(Env::string('INTEGRATION_BASE_URL', 'http://api:8080'), '/'),
    );
    exit($suite->run());
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] Stage-2 runner could not start: ' . $exception->getMessage() . "\n");
    exit(1);
}
