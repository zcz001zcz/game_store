<?php

declare(strict_types=1);

namespace GameStore\Core\Http;

use GameStore\Core\Log\JsonLogger;
use Throwable;

final class Kernel
{
	public function __construct(
		private readonly Router $router,
		private readonly JsonLogger $logger,
		private readonly bool $debug = false,
	) {
	}

	public function handle(Request $request): Response
	{
		$requestId = $request->header('x-request-id') ?: bin2hex(random_bytes(8));

		try {
			$route = $this->router->match($request->method, $request->path);
			$response = ($route['handler'])($request, $route['params']);

			if (!$response instanceof Response) {
				throw new \LogicException('Route handler must return a Response');
			}

			return new Response(
				$response->status,
				$response->body,
				array_merge($response->headers, ['X-Request-Id' => $requestId]),
			);
		} catch (HttpException $exception) {
			$this->logger->warning('http_request_rejected', [
				'request_id' => $requestId,
				'method' => $request->method,
				'path' => $request->path,
				'status' => $exception->status,
				'error_code' => $exception->errorCode,
			]);

			return Response::json([
				'error' => [
					'code' => $exception->errorCode,
					'message' => $exception->getMessage(),
					'details' => $exception->details,
				],
			], $exception->status, ['X-Request-Id' => $requestId]);
		} catch (Throwable $exception) {
			$this->logger->error('unhandled_http_exception', [
				'request_id' => $requestId,
				'method' => $request->method,
				'path' => $request->path,
				'exception' => $exception::class,
				'message' => $exception->getMessage(),
			]);

			$details = $this->debug ? [
				'exception' => $exception::class,
				'message' => $exception->getMessage(),
			] : [];

			return Response::json([
				'error' => [
					'code' => 'internal_error',
					'message' => 'Internal server error',
					'details' => $details,
				],
			], 500, ['X-Request-Id' => $requestId]);
		}
	}
}
