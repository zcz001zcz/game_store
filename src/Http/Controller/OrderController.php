<?php

declare(strict_types=1);

namespace GameStore\Http\Controller;

use GameStore\Application\OrderService;
use GameStore\Application\OrderHistoryService;
use GameStore\Core\Http\BadRequestException;
use GameStore\Core\Http\Request;
use GameStore\Core\Http\Response;

final class OrderController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderHistoryService $history,
    ) {
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        $payload = $request->json();
        $result = $this->orders->create($payload);
        $status = $result['idempotent'] ? 200 : 201;
        $headers = ['Cache-Control' => 'no-store'];

        // PHP changes a 200 response to 302 when a Location header is emitted.
        // Location is useful only for the initial 201 Created response; an
        // idempotent replay already returns the existing resource representation.
        if (!$result['idempotent']) {
            $headers['Location'] = '/api/v1/orders/' . $result['order']['id'];
        }

        return Response::json([
            'data' => $result['order'],
            'meta' => ['idempotent_replay' => $result['idempotent']],
        ], $status, $headers);
    }

    /** @param array<string, string> $params */
    public function stateAt(Request $request, array $params): Response
    {
        $at = $request->query['at'] ?? '';

        if (!is_string($at)) {
            throw new BadRequestException('at must be a string');
        }

        return Response::json([
            'data' => $this->history->stateAt($params['id'] ?? '', $at),
        ], headers: ['Cache-Control' => 'no-store']);
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
