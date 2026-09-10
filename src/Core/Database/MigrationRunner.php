<?php

declare(strict_types=1);

namespace GameStore\Core\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> */
    public function run(): array
    {
        if (!is_dir($this->directory)) {
            throw new RuntimeException('Migration directory does not exist: ' . $this->directory);
        }

        $files = glob($this->directory . '/*.sql');

        if ($files === false) {
            throw new RuntimeException('Cannot read migration directory');
        }

        sort($files, SORT_STRING);
        $applied = [];

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (' .
            'version VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW())'
        );
        $this->pdo->exec("SELECT pg_advisory_lock(hashtext('game_store_migrations'))");

        try {
            foreach ($files as $file) {
                $version = basename($file);
                $statement = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
                $statement->execute(['version' => $version]);

                if ($statement->fetchColumn() !== false) {
                    continue;
                }

                $sql = file_get_contents($file);

                if ($sql === false) {
                    throw new RuntimeException('Cannot read migration: ' . $version);
                }

                $this->pdo->beginTransaction();

                try {
                    $this->pdo->exec($sql);
                    $insert = $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
                    $insert->execute(['version' => $version]);
                    $this->pdo->commit();
                    $applied[] = $version;
                } catch (\Throwable $exception) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }

                    throw $exception;
                }
            }
        } finally {
            $this->pdo->exec("SELECT pg_advisory_unlock(hashtext('game_store_migrations'))");
        }

        return $applied;
    }
}
