<?php

declare(strict_types=1);

namespace GameStore\Http\Controller;

use GameStore\Application\PaymentService;
use GameStore\Core\Http\Request;
use GameStore\Core\Http\Response;

final class PaymentWebhookController
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    /** @param array<string, string> $params */
    public function handle(Request $request, array $params): Response
    {
        $result = $this->payments->accept($request->json());

        return Response::json(['data' => $result], 200);
    }
}
