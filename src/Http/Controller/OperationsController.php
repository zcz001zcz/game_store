<?php

declare(strict_types=1);

namespace GameStore\Http\Controller;

use GameStore\Application\ReconciliationService;
use GameStore\Application\OrderHistoryService;
use GameStore\Core\Http\BadRequestException;
use GameStore\Core\Http\Request;
use GameStore\Core\Http\Response;
use GameStore\Infrastructure\Queue\QueueRepository;

final class OperationsController
{
    public function __construct(
        private readonly ReconciliationService $reconciliation,
        private readonly QueueRepository $queue,
        private readonly OrderHistoryService $history,
    ) {
    }

    /** @param array<string, string> $params */
    public function reconciliation(Request $request, array $params): Response
    {
        $limit = isset($request->query['limit']) ? filter_var($request->query['limit'], FILTER_VALIDATE_INT) : 100;

        if ($limit === false) {
            throw new BadRequestException('limit must be an integer');
        }

        return Response::json(['data' => $this->reconciliation->report((int) $limit)]);
    }

    /** @param array<string, string> $params */
    public function recover(Request $request, array $params): Response
    {
        $payload = $request->json();
        $orderId = null;

        if (array_key_exists('order_id', $payload)) {
            if (!is_string($payload['order_id']) || !preg_match('/^ord_[A-Za-z0-9_-]{3,70}$/', $payload['order_id'])) {
                throw new BadRequestException('Invalid order_id');
            }

            $orderId = trim($payload['order_id']);
        }

        if (isset($payload['limit']) && !is_int($payload['limit'])) {
            throw new BadRequestException('limit must be an integer');
        }

        if (isset($payload['older_than_seconds']) && !is_int($payload['older_than_seconds'])) {
            throw new BadRequestException('older_than_seconds must be an integer');
        }

        $limit = $payload['limit'] ?? 20;
        $olderThan = $payload['older_than_seconds'] ?? 30;
        $recovered = $this->reconciliation->recoverStuck($limit, $olderThan, $orderId);

        return Response::json([
            'data' => [
                'requeued_orders' => $recovered,
                'count' => count($recovered),
            ],
        ]);
    }

    /** @param array<string, string> $params */
    public function queueProgress(Request $request, array $params): Response
    {
        return Response::json(['data' => $this->queue->progress()]);
    }

    /** @param array<string, string> $params */
    public function historySummary(Request $request, array $params): Response
    {
        $from = $request->query['from'] ?? '';
        $to = $request->query['to'] ?? '';
        $orderId = $request->query['order_id'] ?? null;

        if (!is_string($from) || !is_string($to) || ($orderId !== null && !is_string($orderId))) {
            throw new BadRequestException('from, to and order_id must be strings');
        }

        return Response::json([
            'data' => $this->history->periodSummary($from, $to, $orderId),
        ]);
    }
}
