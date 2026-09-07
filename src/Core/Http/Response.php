<?php

declare(strict_types=1);

namespace GameStore\Core\Http;

use JsonException;

final class Response
{
	/** @param array<string, string> $headers */
	public function __construct(
		public readonly int $status,
		public readonly string $body = '',
		public readonly array $headers = [],
	) {
	}

	/** @param array<string, mixed>|list<mixed> $data */
	public static function json(array $data, int $status = 200, array $headers = []): self
	{
		try {
			$body = json_encode(
				$data,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
			);
		} catch (JsonException $exception) {
			throw new \RuntimeException('Cannot encode JSON response', 0, $exception);
		}

		return new self($status, $body, array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers));
	}

	public function send(): void
	{
		http_response_code($this->status);

		foreach ($this->headers as $name => $value) {
			header($name . ': ' . $value);
		}

		echo $this->body;
	}
}
