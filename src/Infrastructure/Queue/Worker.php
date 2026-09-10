<?php

declare(strict_types=1);

namespace GameStore\Infrastructure\Queue;

use GameStore\Application\DeliveryService;
use GameStore\Core\Log\JsonLogger;
use GameStore\Domain\Provider\RetryableDeliveryException;
use GameStore\Domain\Provider\ProviderRateLimitedException;
use Throwable;

final class Worker
{
    public function __construct(
        private readonly QueueRepository $queue,
        private readonly DeliveryService $delivery,
        private readonly JsonLogger $logger,
        private readonly int $baseRetryMs,
        private readonly int $staleAfterSeconds,
    ) {
    }

    public function runOnce(string $workerId): bool
    {
        $job = $this->queue->reserve($workerId, $this->staleAfterSeconds);

        if ($job === null) {
            return false;
        }

        $jobId = (int) $job['id'];
        $attempt = (int) $job['attempts'];
        $maxAttempts = (int) $job['max_attempts'];
        $orderPublicId = is_array($job['payload']) ? (string) ($job['payload']['order_id'] ?? '') : '';

        $this->logger->info('job_started', [
            'job_id' => $jobId,
            'job_type' => $job['type'],
            'attempt' => $attempt,
            'worker_id' => $workerId,
        ]);

        try {
            if ((string) $job['type'] !== 'issue_order' || $orderPublicId === '') {
                throw new \LogicException('Unsupported or malformed job');
            }

            $this->delivery->deliver($orderPublicId);
            $this->queue->complete($jobId, $workerId);
            $this->logger->info('job_completed', ['job_id' => $jobId, 'worker_id' => $workerId]);

            return true;
        } catch (Throwable $exception) {
            $retryable = $exception instanceof RetryableDeliveryException || !($exception instanceof \LogicException);

            if ($exception instanceof ProviderRateLimitedException) {
                $this->queue->defer($jobId, $workerId, $exception->getMessage(), $exception->retryAfterMs);
                $this->logger->info('job_rate_limited', [
                    'job_id' => $jobId,
                    'worker_id' => $workerId,
                    'delay_ms' => $exception->retryAfterMs,
                ]);

                return true;
            }

            if (!$retryable || $attempt >= $maxAttempts) {
                $this->queue->markDead($jobId, $workerId, $exception->getMessage());

                if ($orderPublicId !== '') {
                    $this->delivery->markExhausted($orderPublicId, $exception->getMessage());
                }

                $this->logger->error('job_dead', [
                    'job_id' => $jobId,
                    'worker_id' => $workerId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                return true;
            }

            $delay = Backoff::milliseconds($attempt, $this->baseRetryMs);
            $this->queue->retry($jobId, $workerId, $exception->getMessage(), $delay);
            $this->logger->warning('job_retried', [
                'job_id' => $jobId,
                'worker_id' => $workerId,
                'delay_ms' => $delay,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return true;
        }
    }
}
