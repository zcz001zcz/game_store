<?php

declare(strict_types=1);

namespace GameStore;

use GameStore\Application\DeliveryService;
use GameStore\Application\OrderService;
use GameStore\Application\OrderEventStore;
use GameStore\Application\OrderHistoryService;
use GameStore\Application\PaymentService;
use GameStore\Application\ProviderStubService;
use GameStore\Application\ReconciliationService;
use GameStore\Core\Database\ConnectionFactory;
use GameStore\Core\Database\Database;
use GameStore\Core\Http\Kernel;
use GameStore\Core\Http\Response;
use GameStore\Core\Http\Router;
use GameStore\Core\Log\JsonLogger;
use GameStore\Http\Controller\OperationsController;
use GameStore\Http\Controller\OrderController;
use GameStore\Http\Controller\PaymentWebhookController;
use GameStore\Http\Controller\ProviderStubController;
use GameStore\Infrastructure\Provider\ProviderHttpClient;
use GameStore\Infrastructure\Provider\ProviderRateLimiter;
use GameStore\Infrastructure\Queue\QueueRepository;
use GameStore\Infrastructure\Queue\Worker;
use GameStore\Support\Env;

final class AppFactory
{
    /** @return array{kernel: Kernel, worker: Worker, reconciliation: ReconciliationService} */
    public static function create(): array
    {
        $pdo = ConnectionFactory::create();
        $database = new Database($pdo);
        $logger = new JsonLogger();
        $queue = new QueueRepository($database);
        $events = new OrderEventStore();
        $history = new OrderHistoryService($database);
        $payments = new PaymentService($database, $queue, $logger, $events);
        $orders = new OrderService($database, $payments, $events);
        $providerStubs = new ProviderStubService($database, $logger);
        $providerClient = new ProviderHttpClient(
            [
                'A' => Env::string('PROVIDER_A_URL', 'http://api:8080/stubs/providers/a/issue'),
                'B' => Env::string('PROVIDER_B_URL', 'http://api:8080/stubs/providers/b/issue'),
            ],
            Env::int('PROVIDER_CONNECT_TIMEOUT_MS', 300),
            Env::int('PROVIDER_TIMEOUT_MS', 800),
            $logger,
        );
        $rateLimiter = new ProviderRateLimiter($database);
        $delivery = new DeliveryService($database, $providerClient, $rateLimiter, $events, $logger);
        $reconciliation = new ReconciliationService($database, $queue, $events);
        $worker = new Worker(
            $queue,
            $delivery,
            $logger,
            Env::int('QUEUE_BASE_RETRY_MS', 500),
            Env::int('QUEUE_STALE_AFTER_SECONDS', 5),
        );

        $orderController = new OrderController($orders, $history);
        $webhookController = new PaymentWebhookController($payments);
        $providerController = new ProviderStubController($providerStubs);
        $operationsController = new OperationsController($reconciliation, $queue, $history);
        $router = new Router();
        $router->get('/health', static fn (): Response => Response::json(['status' => 'ok']));
        $router->get('/api/v1/catalog', [$orderController, 'catalog']);
        $router->post('/api/v1/orders', [$orderController, 'create']);
        $router->get('/api/v1/orders/{id}', [$orderController, 'get']);
        $router->get('/api/v1/orders/{id}/state-at', [$orderController, 'stateAt']);
        $router->post('/api/v1/webhooks/payment', [$webhookController, 'handle']);
        $router->post('/stubs/providers/{provider}/issue', [$providerController, 'issue']);
        $router->get('/stubs/providers/{provider}/issues/{request_id}', [$providerController, 'audit']);
        $router->get('/api/v1/dev/providers/{provider}', [$providerController, 'getSettings']);
        $router->put('/api/v1/dev/providers/{provider}', [$providerController, 'configure']);
        $router->get('/api/v1/ops/reconciliation', [$operationsController, 'reconciliation']);
        $router->post('/api/v1/ops/recovery', [$operationsController, 'recover']);
        $router->get('/api/v1/ops/queue-progress', [$operationsController, 'queueProgress']);
        $router->get('/api/v1/ops/history-summary', [$operationsController, 'historySummary']);

        return [
            'kernel' => new Kernel($router, $logger, Env::bool('APP_DEBUG', false)),
            'worker' => $worker,
            'reconciliation' => $reconciliation,
        ];
    }
}
