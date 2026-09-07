<?php

declare(strict_types=1);

namespace GameStore\Core\Log;

use JsonException;

final class JsonLogger
{
	/** @param array<string, mixed> $context */
	public function info(string $message, array $context = []): void
	{
		$this->write('info', $message, $context);
	}

	/** @param array<string, mixed> $context */
	public function warning(string $message, array $context = []): void
	{
		$this->write('warning', $message, $context);
	}

	/** @param array<string, mixed> $context */
	public function error(string $message, array $context = []): void
	{
		$this->write('error', $message, $context);
	}

	/** @param array<string, mixed> $context */
	private function write(string $level, string $message, array $context): void
	{
		$record = array_merge(
			[
				'timestamp' => gmdate('c'),
				'level' => $level,
				'message' => $message,
			],
			$context,
		);

		try {
			$line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		} catch (JsonException) {
			$line = sprintf('{"timestamp":"%s","level":"error","message":"log_encoding_failed"}', gmdate('c'));
		}

		file_put_contents('php://stderr', $line . PHP_EOL);
	}
}
