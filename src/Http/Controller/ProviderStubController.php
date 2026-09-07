<?php

declare(strict_types=1);

namespace GameStore\Http\Controller;

use GameStore\Application\ProviderStubService;
use GameStore\Core\Http\HttpException;
use GameStore\Core\Http\Request;
use GameStore\Core\Http\Response;
use GameStore\Support\Env;

final class ProviderStubController
{
	public function __construct(private readonly ProviderStubService $providers)
	{
	}

	/** @param array<string, string> $params */
	public function issue(Request $request, array $params): Response
	{
		$result = $this->providers->issue($params['provider'] ?? '', $request->json());

		return Response::json($result['body'], $result['status']);
	}

	/** @param array<string, string> $params */
	public function configure(Request $request, array $params): Response
	{
		$this->assertDevelopmentEnvironment();
		$settings = $this->providers->configure($params['provider'] ?? '', $request->json());

		return Response::json(['data' => $settings]);
	}

	/** @param array<string, string> $params */
	public function getSettings(Request $request, array $params): Response
	{
		$this->assertDevelopmentEnvironment();

		return Response::json(['data' => $this->providers->getSettings($params['provider'] ?? '')]);
	}

	private function assertDevelopmentEnvironment(): void
	{
		if (in_array(strtolower(Env::string('APP_ENV', 'dev')), ['prod', 'production'], true)) {
			throw new HttpException(404, 'Resource not found', 'not_found');
		}
	}
}
