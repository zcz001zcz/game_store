<?php

declare(strict_types=1);

namespace GameStore\Infrastructure\Provider;

use GameStore\Core\Log\JsonLogger;
use GameStore\Domain\Provider\ProviderResponse;
use JsonException;

final class ProviderHttpClient
{
	/** @param array<string, string> $urls */
	public function __construct(
		private readonly array $urls,
		private readonly int $connectTimeoutMs,
		private readonly int $timeoutMs,
		private readonly JsonLogger $logger,
	) {
	}

	public function issue(string $provider, string $requestId, string $sku, string $orderId): ProviderResponse
	{
		$url = $this->urls[$provider] ?? null;

		if ($url === null) {
			return ProviderResponse::unavailable('provider_not_configured');
		}

		$payload = json_encode([
			'request_id' => $requestId,
			'sku' => $sku,
			'order_id' => $orderId,
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		$curl = curl_init($url);

		if ($curl === false) {
			return ProviderResponse::uncertain('curl_init_failed');
		}

		curl_setopt_array($curl, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $payload,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Request-Id: ' . $requestId],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
			CURLOPT_TIMEOUT_MS => $this->timeoutMs,
		]);

		$body = curl_exec($curl);
		$errno = curl_errno($curl);
		$error = curl_error($curl);
		$httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		curl_close($curl);

		if ($body === false) {
			$this->logger->warning('provider_transport_error', [
				'provider' => $provider,
				'request_id' => $requestId,
				'curl_errno' => $errno,
				'error' => $error,
			]);

			if (in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true)) {
				return ProviderResponse::unavailable('connection_not_established');
			}

			return ProviderResponse::uncertain($errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'transport_error');
		}

		try {
			$decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return ProviderResponse::uncertain('invalid_json_response');
		}

		if (!is_array($decoded)) {
			return ProviderResponse::uncertain('invalid_response_shape');
		}

		if ($httpStatus === 200 && ($decoded['status'] ?? null) === 'ok') {
			if (($decoded['request_id'] ?? null) !== $requestId || !is_string($decoded['code'] ?? null)) {
				return ProviderResponse::uncertain('invalid_success_response');
			}

			return ProviderResponse::success($decoded['code']);
		}

		$reason = is_string($decoded['reason'] ?? null) ? $decoded['reason'] : 'provider_error';

		if ($reason === 'out_of_stock') {
			return ProviderResponse::outOfStock();
		}

		if ($reason === 'unavailable_before_issue') {
			return ProviderResponse::unavailable($reason);
		}

		return ProviderResponse::uncertain($reason);
	}
}
