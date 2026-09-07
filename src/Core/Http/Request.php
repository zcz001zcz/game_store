<?php

declare(strict_types=1);

namespace GameStore\Core\Http;

use JsonException;

final class Request
{
	/** @var array<string, string> */
	private array $headers;

	/** @var array<string, mixed>|null */
	private ?array $json = null;

	private bool $jsonWasParsed = false;

	/**
	 * @param array<string, string> $headers
	 * @param array<string, string> $query
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $path,
		array $headers,
		public readonly array $query,
		private readonly string $body,
	) {
		$this->headers = array_change_key_case($headers, CASE_LOWER);
	}

	public static function fromGlobals(): self
	{
		$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
		$uri = $_SERVER['REQUEST_URI'] ?? '/';
		$path = parse_url($uri, PHP_URL_PATH) ?: '/';
		$headers = [];

		foreach ($_SERVER as $key => $value) {
			if (!is_string($value)) {
				continue;
			}

			if (str_starts_with($key, 'HTTP_')) {
				$name = str_replace('_', '-', strtolower(substr($key, 5)));
				$headers[$name] = $value;
			}
		}

		if (isset($_SERVER['CONTENT_TYPE'])) {
			$headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
		}

		$body = file_get_contents('php://input');

		return new self($method, $path, $headers, $_GET, $body === false ? '' : $body);
	}

	public function header(string $name): ?string
	{
		return $this->headers[strtolower($name)] ?? null;
	}

	/** @return array<string, mixed> */
	public function json(): array
	{
		if ($this->jsonWasParsed) {
			return $this->json ?? [];
		}

		$this->jsonWasParsed = true;

		if ($this->body === '') {
			throw new BadRequestException('JSON request body is required');
		}

		if (strlen($this->body) > 65_536) {
			throw new BadRequestException('Request body is too large');
		}

		$contentType = strtolower((string) $this->header('content-type'));

		if ($contentType !== '' && !str_contains($contentType, 'application/json')) {
			throw new BadRequestException('Content-Type must be application/json');
		}

		try {
			$decoded = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new BadRequestException('Malformed JSON body', ['json_error' => $exception->getMessage()]);
		}

		if (!is_array($decoded) || array_is_list($decoded)) {
			throw new BadRequestException('JSON body must be an object');
		}

		$this->json = $decoded;

		return $decoded;
	}
}
