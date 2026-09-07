<?php

declare(strict_types=1);

namespace GameStore\Core\Http;

final class Router
{
	/** @var list<array{method: string, template: string, regex: string, handler: callable}> */
	private array $routes = [];

	public function get(string $path, callable $handler): void
	{
		$this->add('GET', $path, $handler);
	}

	public function post(string $path, callable $handler): void
	{
		$this->add('POST', $path, $handler);
	}

	public function put(string $path, callable $handler): void
	{
		$this->add('PUT', $path, $handler);
	}

	public function add(string $method, string $path, callable $handler): void
	{
		$template = $this->normalizePath($path);
		$segments = explode('/', trim($template, '/'));
		$compiled = [];

		foreach ($segments as $segment) {
			if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches) === 1) {
				$compiled[] = '(?P<' . $matches[1] . '>[^/]+)';
				continue;
			}

			$compiled[] = preg_quote($segment, '#');
		}

		$regex = $template === '/' ? '#^/$#' : '#^/' . implode('/', $compiled) . '$#';

		$this->routes[] = [
			'method' => strtoupper($method),
			'template' => $template,
			'regex' => $regex,
			'handler' => $handler,
		];
	}

	/** @return array{handler: callable, params: array<string, string>} */
	public function match(string $method, string $path): array
	{
		$normalizedPath = $this->normalizePath($path);
		$allowedMethods = [];

		foreach ($this->routes as $route) {
			if (preg_match($route['regex'], $normalizedPath, $matches) !== 1) {
				continue;
			}

			if ($route['method'] !== strtoupper($method)) {
				$allowedMethods[] = $route['method'];
				continue;
			}

			$params = [];

			foreach ($matches as $key => $value) {
				if (is_string($key)) {
					$params[$key] = rawurldecode($value);
				}
			}

			return ['handler' => $route['handler'], 'params' => $params];
		}

		if ($allowedMethods !== []) {
			throw new HttpException(405, 'Method not allowed', 'method_not_allowed', [
				'allowed_methods' => array_values(array_unique($allowedMethods)),
			]);
		}

		throw new NotFoundException();
	}

	private function normalizePath(string $path): string
	{
		if ($path === '' || $path === '/') {
			return '/';
		}

		return '/' . trim($path, '/');
	}
}
