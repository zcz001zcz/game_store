<?php

declare(strict_types=1);

namespace GameStore\Infrastructure\Provider;

use GameStore\Core\Log\JsonLogger;
use GameStore\Domain\Provider\ProviderAuditResult;
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
        [$body, $httpStatus, $errno, $error] = $this->request($url, $payload, $requestId);

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

        $decoded = $this->decode($body);

        if ($decoded === null) {
            return ProviderResponse::uncertain('invalid_json_response');
        }

        if ($httpStatus === 200 && ($decoded['status'] ?? null) === 'ok') {
            if (($decoded['request_id'] ?? null) !== $requestId || !is_string($decoded['code'] ?? null)) {
                return ProviderResponse::uncertain('invalid_success_response');
            }

            $fault = is_string($decoded['test_fault'] ?? null) ? $decoded['test_fault'] : null;

            return ProviderResponse::success($decoded['code'], $fault);
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

    public function audit(string $provider, string $requestId): ProviderAuditResult
    {
        $issueUrl = $this->urls[$provider] ?? null;

        if ($issueUrl === null) {
            return ProviderAuditResult::uncertain('provider_not_configured');
        }

        $url = preg_replace('#/issue/?$#', '/issues/' . rawurlencode($requestId), $issueUrl);

        if (!is_string($url) || $url === $issueUrl) {
            return ProviderAuditResult::uncertain('audit_endpoint_not_configured');
        }

        $curl = curl_init($url);

        if ($curl === false) {
            return ProviderAuditResult::uncertain('curl_init_failed');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Request-Id: audit-' . $requestId],
        ]);
        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false) {
            return ProviderAuditResult::uncertain($errno === CURLE_OPERATION_TIMEDOUT ? 'audit_timeout' : 'audit_transport_error');
        }

        $decoded = $this->decode($body);

        if ($decoded === null) {
            return ProviderAuditResult::uncertain('invalid_audit_response');
        }

        $errorCode = is_array($decoded['error'] ?? null) ? ($decoded['error']['code'] ?? null) : null;

        if ($httpStatus === 404 && (($decoded['status'] ?? null) === 'not_found' || $errorCode === 'not_found')) {
            return ProviderAuditResult::notFound();
        }

        if ($httpStatus !== 200 || ($decoded['status'] ?? null) !== 'ok') {
            return ProviderAuditResult::uncertain('audit_unavailable');
        }

        foreach (['request_id', 'order_id', 'requested_sku', 'actual_sku', 'code'] as $field) {
            if (!is_string($decoded[$field] ?? null)) {
                return ProviderAuditResult::uncertain('invalid_audit_response');
            }
        }

        if ($decoded['request_id'] !== $requestId) {
            return ProviderAuditResult::uncertain('audit_request_id_mismatch');
        }

        return ProviderAuditResult::found(
            $decoded['code'],
            $decoded['order_id'],
            $decoded['requested_sku'],
            $decoded['actual_sku'],
        );
    }

    /** @return array{string|false, int, int, string} */
    private function request(string $url, string $payload, string $requestId): array
    {
        $curl = curl_init($url);

        if ($curl === false) {
            return [false, 0, CURLE_FAILED_INIT, 'curl_init_failed'];
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

        return [$body, $httpStatus, $errno, $error];
    }

    /** @return array<string, mixed>|null */
    private function decode(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
