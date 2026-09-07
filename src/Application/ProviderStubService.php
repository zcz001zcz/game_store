<?php

declare(strict_types=1);

namespace GameStore\Application;

use GameStore\Core\Database\Database;
use GameStore\Core\Http\BadRequestException;
use GameStore\Core\Http\ConflictException;
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

			$existingStatement = $pdo->prepare(
				'SELECT request_id, order_public_id, sku, code FROM provider_issues '
				. 'WHERE provider = :provider AND request_id = :request_id'
			);
			$existingStatement->execute(['provider' => $provider, 'request_id' => $requestId]);
			$existing = $existingStatement->fetch();

			if (is_array($existing)) {
				if ((string) $existing['sku'] !== $sku || (string) $existing['order_public_id'] !== $orderId) {
					return [
						'status' => 409,
						'body' => ['status' => 'error', 'reason' => 'idempotency_conflict'],
						'timeout_ms' => 0,
					];
				}

				return [
					'status' => 200,
					'body' => [
						'status' => 'ok',
						'request_id' => $requestId,
						'code' => $existing['code'],
					],
					'timeout_ms' => 0,
				];
			}

			$settingsStatement = $pdo->prepare('SELECT * FROM stub_provider_settings WHERE provider = :provider');
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

				if ($roll <= $failureRate) {
					$mode = 'fail_before_issue';
				} elseif ($roll <= $failureRate + $timeoutRate) {
					$mode = 'timeout_after_issue';
				} else {
					$mode = 'success';
				}
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

			$stockStatement = $pdo->prepare(
				'SELECT id, code FROM provider_stock '
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

			$issue = $pdo->prepare(
				'INSERT INTO provider_issues '
				. '(provider, request_id, order_public_id, sku, stock_id, code) '
				. 'VALUES (:provider, :request_id, :order_id, :sku, :stock_id, :code)'
			);
			$issue->execute([
				'provider' => $provider,
				'request_id' => $requestId,
				'order_id' => $orderId,
				'sku' => $sku,
				'stock_id' => $stock['id'],
				'code' => $stock['code'],
			]);

			$markStock = $pdo->prepare('UPDATE provider_stock SET issued_at = NOW() WHERE id = :id');
			$markStock->execute(['id' => $stock['id']]);

			return [
				'status' => 200,
				'body' => [
					'status' => 'ok',
					'request_id' => $requestId,
					'code' => $stock['code'],
				],
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

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public function configure(string $provider, array $settings): array
	{
		$provider = $this->normalizeProvider($provider);
		$mode = isset($settings['mode']) && is_string($settings['mode']) ? trim($settings['mode']) : 'random';
		$failureRate = $settings['failure_rate'] ?? 0;
		$timeoutRate = $settings['timeout_rate'] ?? 0;
		$timeoutMs = $settings['timeout_ms'] ?? 1500;

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

		if ((float) $failureRate + (float) $timeoutRate > 1) {
			throw new BadRequestException('failure_rate + timeout_rate cannot exceed 1');
		}

		$statement = $this->database->connection()->prepare(
			'UPDATE stub_provider_settings '
			. 'SET mode = :mode, failure_rate = :failure_rate, timeout_rate = :timeout_rate, '
			. 'timeout_ms = :timeout_ms, updated_at = NOW() WHERE provider = :provider RETURNING *'
		);
		$statement->execute([
			'mode' => $mode,
			'failure_rate' => (float) $failureRate,
			'timeout_rate' => (float) $timeoutRate,
			'timeout_ms' => $timeoutMs,
			'provider' => $provider,
		]);
		$updated = $statement->fetch();

		if (!is_array($updated)) {
			throw new ConflictException('Provider settings could not be updated');
		}

		return $this->presentSettings($updated);
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

	private function normalizeProvider(string $provider): string
	{
		$provider = strtoupper(trim($provider));

		if (!in_array($provider, ['A', 'B'], true)) {
			throw new BadRequestException('Provider must be A or B');
		}

		return $provider;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function presentSettings(array $settings): array
	{
		return [
			'provider' => trim((string) $settings['provider']),
			'mode' => $settings['mode'],
			'failure_rate' => (float) $settings['failure_rate'],
			'timeout_rate' => (float) $settings['timeout_rate'],
			'timeout_ms' => (int) $settings['timeout_ms'],
			'available_codes' => isset($settings['available_codes']) ? (int) $settings['available_codes'] : null,
			'updated_at' => $settings['updated_at'],
		];
	}
}
