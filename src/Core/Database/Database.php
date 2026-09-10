<?php

declare(strict_types=1);

namespace GameStore\Core\Database;

use PDO;
use PDOException;
use Throwable;

final class Database
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    /**
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public function transaction(callable $callback, int $maxAttempts = 3): mixed
    {
        $attempt = 0;

        while (true) {
            ++$attempt;
            $this->pdo->beginTransaction();

            try {
                $result = $callback($this->pdo);
                $this->pdo->commit();

                return $result;
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                if ($exception instanceof PDOException && $attempt < $maxAttempts && $this->isRetryable($exception)) {
                    usleep(random_int(10_000, 50_000) * $attempt);
                    continue;
                }

                throw $exception;
            }
        }
    }

    private function isRetryable(PDOException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['40001', '40P01'], true);
    }
}
