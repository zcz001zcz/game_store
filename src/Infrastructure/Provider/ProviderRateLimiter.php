<?php

declare(strict_types=1);

namespace GameStore\Infrastructure\Provider;

use GameStore\Core\Database\Database;
use GameStore\Domain\Provider\ProviderRateLimitedException;
use PDO;

/** A PostgreSQL-backed limiter shared by every worker process. */
final class ProviderRateLimiter
{
    public function __construct(private readonly Database $database)
    {
    }

    public function acquire(string $provider, string $requestId): void
    {
        $this->database->transaction(function (PDO $pdo) use ($provider, $requestId): void {
            $lock = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:key))');
            $lock->execute(['key' => 'provider-rate-limit:' . $provider]);

            $settings = $pdo->prepare(
                'SELECT rate_limit, rate_window_seconds FROM stub_provider_settings WHERE provider = :provider'
            );
            $settings->execute(['provider' => $provider]);
            $row = $settings->fetch();

            if (!is_array($row)) {
                throw new \LogicException('Provider rate-limit settings are missing');
            }

            $limit = (int) $row['rate_limit'];
            $window = (int) $row['rate_window_seconds'];
            $windowState = $pdo->prepare(
                "SELECT COUNT(*) AS request_count, MIN(requested_at) AS oldest_request
                 FROM provider_request_log
                 WHERE provider = :provider
                   AND requested_at > clock_timestamp() - (:window * INTERVAL '1 second')"
            );
            $windowState->bindValue('provider', $provider);
            $windowState->bindValue('window', $window, PDO::PARAM_INT);
            $windowState->execute();
            $limitRow = $windowState->fetch();

            if (is_array($limitRow) && (int) $limitRow['request_count'] >= $limit) {
                $delay = $pdo->prepare(
                    "SELECT GREATEST(1, CEIL(EXTRACT(EPOCH FROM
                     ((CAST(:oldest AS timestamptz) + (:window * INTERVAL '1 second')) - clock_timestamp())) * 1000))"
                );
                $delay->bindValue('oldest', (string) $limitRow['oldest_request']);
                $delay->bindValue('window', $window, PDO::PARAM_INT);
                $delay->execute();
                $retryAfterMs = max(1, (int) $delay->fetchColumn() + 25);
                throw new ProviderRateLimitedException($retryAfterMs);
            }

            $insert = $pdo->prepare(
                'INSERT INTO provider_request_log (provider, request_id) VALUES (:provider, :request_id)'
            );
            $insert->execute(['provider' => $provider, 'request_id' => $requestId]);
        });
    }
}
