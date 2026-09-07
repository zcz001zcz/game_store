<?php

declare(strict_types=1);

namespace GameStore\Http\Controller;

use GameStore\Application\OrderService;
use GameStore\Core\Http\BadRequestException;
use GameStore\Core\Http\Request;
use GameStore\Core\Http\Response;

final class OrderController
{
	public function __construct(private readonly OrderService $orders)
	{
	}

	/** @param array<string, string> $params */
	public function create(Request $request, array $params): Response
	{
		$payload = $request->json();
		$sku = isset($payload['sku']) && is_string($payload['sku']) ? $payload['sku'] : '';

		if ($sku === '') {
			throw new BadRequestException('sku is required');
		}

		$requestedId = null;

		if (array_key_exists('order_id', $payload)) {
			if (!is_string($payload['order_id'])) {
				throw new BadRequestException('order_id must be a string');
			}

			$requestedId = $payload['order_id'];
		}

		$result = $this->orders->create($sku, $requestedId);
		$status = $result['idempotent'] ? 200 : 201;

		return Response::json([
			'data' => $result['order'],
			'meta' => ['idempotent_replay' => $result['idempotent']],
		], $status, [
			'Location' => '/api/v1/orders/' . $result['order']['id'],
			'Cache-Control' => 'no-store',
		]);
	}

	/** @param array<string, string> $params */
	public function get(Request $request, array $params): Response
	{
		return Response::json(
			['data' => $this->orders->get($params['id'] ?? '')],
			headers: ['Cache-Control' => 'no-store'],
		);
	}

	/** @param array<string, string> $params */
	public function catalog(Request $request, array $params): Response
	{
		$limit = isset($request->query['limit']) ? filter_var($request->query['limit'], FILTER_VALIDATE_INT) : 20;
		$afterId = isset($request->query['after_id'])
			? filter_var($request->query['after_id'], FILTER_VALIDATE_INT)
			: null;

		if ($limit === false || $afterId === false) {
			throw new BadRequestException('limit and after_id must be integers');
		}

		$items = $this->orders->catalog((int) $limit, $afterId === null ? null : (int) $afterId);
		$nextAfterId = $items === [] ? null : $items[array_key_last($items)]['id'];

		return Response::json([
			'data' => $items,
			'meta' => ['next_after_id' => $nextAfterId],
		]);
	}
}
