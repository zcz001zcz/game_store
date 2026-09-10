<?php

declare(strict_types=1);

namespace GameStore\Tests\Integration;

use CurlHandle;
use GameStore\Core\Database\ConnectionFactory;
use GameStore\Support\Env;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';

final class IntegrationFailure extends RuntimeException
{
}

final class IntegrationSuite
{
    private const TEST_ORDER_PREFIX = 'ord_it_';
    private const CATALOG_FIXTURE_SIZE = 2_000;

    private int $passed = 0;
    private int $failed = 0;
    private int $requestSequence = 0;
    private int $catalogAfterId = 0;
    private readonly string $runId;

    /**
     * @var array{
     *     race: string,
     *     fallback: string,
     *     timeout: string,
     *     stock: string,
     *     background: string,
     *     early: string,
     *     reconciliation: string
     * }
     */
    private readonly array $skus;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $baseUrl,
    ) {
        $this->runId = gmdate('YmdHis') . '_' . bin2hex(random_bytes(4));
        $skuSuffix = strtoupper($this->runId);
        $this->skus = [
            'race' => 'ITEST-RACE-' . $skuSuffix,
            'fallback' => 'ITEST-FALLBACK-' . $skuSuffix,
            'timeout' => 'ITEST-TIMEOUT-' . $skuSuffix,
            'stock' => 'ITEST-STOCK-' . $skuSuffix,
            'background' => 'ITEST-BACKGROUND-' . $skuSuffix,
            'early' => 'ITEST-EARLY-' . $skuSuffix,
            'reconciliation' => 'ITEST-RECONCILIATION-' . $skuSuffix,
        ];
    }

    public function run(): int
    {
        $startedAt = microtime(true);
        $lockAcquired = false;

        fwrite(STDOUT, "Game Store integration suite\n");
        fwrite(STDOUT, sprintf("Run: %s\n\n", $this->runId));

        try {
            $lockAcquired = $this->acquireSuiteLock();

            if (!$lockAcquired) {
                throw new IntegrationFailure('Another integration suite is already running.');
            }

            $this->assertDevelopmentConfiguration();
            $this->waitForApi();
            $removedOrders = $this->cleanPreviousRuns();
            $this->provisionTestCatalog();

            if ($removedOrders > 0) {
                fwrite(STDOUT, sprintf("Cleaned %d order(s) from previous integration runs.\n\n", $removedOrders));
            }

            $cases = [
                ['Health and catalog under 50 concurrent requests', fn (): string => $this->testCatalogLoad(), false],
                ['Exactly-once effect under concurrent webhooks', fn (): string => $this->testWebhookRace(), true],
                ['Safe fallback from provider A to B', fn (): string => $this->testFallback(), true],
                ['Uncertain timeout retry with stable request_id', fn (): string => $this->testTimeoutRecovery(), true],
                ['Recoverable out-of-stock state', fn (): string => $this->testOutOfStockRecovery(), true],
                ['Background recovery of a failed delivery', fn (): string => $this->testBackgroundRecovery(), true],
                ['Early and out-of-order webhooks', fn (): string => $this->testWebhookOrdering(), true],
                ['Reconciliation detects controlled inconsistencies', fn (): string => $this->testReconciliationReport(), false],
            ];

            foreach ($cases as [$name, $test, $restoreProviders]) {
                if (!$this->runCase($name, $test, $restoreProviders)) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            $this->failed++;
            fwrite(STDERR, sprintf("[FAIL] Suite setup: %s\n", $exception->getMessage()));
        } finally {
            $this->restoreProviderDefaults(false);

            if ($lockAcquired) {
                $this->pdo->query("SELECT pg_advisory_unlock(hashtext('game-store-integration-suite'))");
            }
        }

        $duration = microtime(true) - $startedAt;
        fwrite(STDOUT, sprintf(
            "\nResult: %d passed, %d failed (%.2fs)\n",
            $this->passed,
            $this->failed,
            $duration,
        ));
        fwrite(STDOUT, sprintf(
            "Current run data is preserved for inspection and will be cleaned before the next run (prefixes %s and ITEST-).\n",
            self::TEST_ORDER_PREFIX,
        ));

        return $this->failed === 0 ? 0 : 1;
    }

    /** @param callable(): string $test */
    private function runCase(string $name, callable $test, bool $restoreProviders = false): bool
    {
        $startedAt = microtime(true);
        $failure = null;
        $detail = '';

        try {
            $detail = $test();
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        if ($restoreProviders) {
            try {
                $this->restoreProviderDefaults(true);
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        $duration = microtime(true) - $startedAt;

        if ($failure !== null) {
            $this->failed++;
            fwrite(STDERR, sprintf("[FAIL] %s (%.2fs)\n       %s\n", $name, $duration, $failure->getMessage()));

            return false;
        }

        $this->passed++;
        $suffix = $detail === '' ? '' : '; ' . $detail;
        fwrite(STDOUT, sprintf("[PASS] %s (%.2fs%s)\n", $name, $duration, $suffix));

        return true;
    }

    private function acquireSuiteLock(): bool
    {
        $value = $this->pdo
            ->query("SELECT pg_try_advisory_lock(hashtext('game-store-integration-suite'))")
            ->fetchColumn();

        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function assertDevelopmentConfiguration(): void
    {
        $expected = [
            'PROVIDER_A_URL' => 'http://api:8080/stubs/providers/a/issue',
            'PROVIDER_B_URL' => 'http://api:8080/stubs/providers/b/issue',
        ];

        foreach ($expected as $environmentKey => $expectedUrl) {
            $actualUrl = rtrim(Env::string($environmentKey, $expectedUrl), '/');

            if ($actualUrl !== $expectedUrl) {
                throw new IntegrationFailure(sprintf(
                    '%s must be %s for deterministic stub tests; got %s. APP_PORT changes only the host port.',
                    $environmentKey,
                    $expectedUrl,
                    $actualUrl,
                ));
            }
        }
    }

    private function waitForApi(): void
    {
        $deadline = microtime(true) + 30;
        $lastError = 'API did not answer';

        do {
            try {
                $response = $this->request('GET', '/health', null, 2_000);

                if ($response['status'] === 200 && ($response['json']['status'] ?? null) === 'ok') {
                    return;
                }

                $lastError = sprintf('unexpected health response: HTTP %d', $response['status']);
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        throw new IntegrationFailure('API health check failed: ' . $lastError);
    }

    private function cleanPreviousRuns(): int
    {
        $activeJobs = $this->scalarInt(
            "SELECT COUNT(*) FROM jobs
             WHERE LEFT(aggregate_id, 7) = 'ord_it_'
               AND status = 'processing'
               AND locked_at > NOW() - INTERVAL '2 minutes'",
        );

        if ($activeJobs > 0) {
            throw new IntegrationFailure('A previous integration run still has an active worker job. Retry shortly.');
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->query("SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_' FOR UPDATE")->fetchAll();
            $this->pdo->query("SELECT id FROM jobs WHERE LEFT(aggregate_id, 7) = 'ord_it_' FOR UPDATE")->fetchAll();
            $this->pdo->exec(
                "UPDATE provider_stock ps
                 SET issued_at = NULL
                 FROM provider_issues pi
                 WHERE ps.id = pi.stock_id
                   AND LEFT(pi.order_public_id, 7) = 'ord_it_'",
            );
            $this->pdo->exec(
                "DELETE FROM provider_discrepancies
                 WHERE order_id IN (SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_')",
            );
            $this->pdo->exec(
                "DELETE FROM quarantined_codes
                 WHERE first_request_id IN (
                     SELECT request_id FROM provider_issues WHERE LEFT(order_public_id, 7) = 'ord_it_'
                 )",
            );
            $this->pdo->exec(
                "DELETE FROM fulfillments
                 WHERE order_id IN (SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_')",
            );
            $this->pdo->exec(
                "DELETE FROM delivery_attempts
                 WHERE order_id IN (SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_')",
            );
            $this->pdo->exec("DELETE FROM provider_issues WHERE LEFT(order_public_id, 7) = 'ord_it_'");
            $this->pdo->exec("DELETE FROM provider_stock WHERE LEFT(code, 6) = 'ITEST-'");
            $this->pdo->exec(
                "DELETE FROM refunds
                 WHERE order_id IN (SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_')",
            );
            $this->pdo->exec(
                "DELETE FROM ledger_entries
                 WHERE order_id IN (SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_')",
            );
            $this->pdo->exec("DELETE FROM payment_events WHERE LEFT(order_public_id, 7) = 'ord_it_'");
            $this->pdo->exec("DELETE FROM jobs WHERE LEFT(aggregate_id, 7) = 'ord_it_'");
            $this->pdo->exec(
                "DELETE FROM order_items
                 WHERE order_id IN (SELECT id FROM orders WHERE LEFT(public_id, 7) = 'ord_it_')",
            );
            $removedOrders = $this->pdo->exec("DELETE FROM orders WHERE LEFT(public_id, 7) = 'ord_it_'");
            $this->pdo->exec("DELETE FROM products WHERE LEFT(sku, 6) = 'ITEST-'");
            $this->pdo->commit();

            return (int) $removedOrders;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function provisionTestCatalog(): void
    {
        $products = [
            ['sku' => $this->skus['race'], 'name' => 'Integration race product', 'price_minor' => 50_000],
            ['sku' => $this->skus['fallback'], 'name' => 'Integration fallback product', 'price_minor' => 100_000],
            ['sku' => $this->skus['timeout'], 'name' => 'Integration timeout product', 'price_minor' => 250_000],
            ['sku' => $this->skus['stock'], 'name' => 'Integration stock product', 'price_minor' => 129_000],
            ['sku' => $this->skus['background'], 'name' => 'Integration background recovery product', 'price_minor' => 75_000],
            ['sku' => $this->skus['early'], 'name' => 'Integration ordering product', 'price_minor' => 39_900],
            ['sku' => $this->skus['reconciliation'], 'name' => 'Integration reconciliation product', 'price_minor' => 64_000],
        ];
        $stock = [
            ['A', $this->skus['race'], 'RACE-A-1'],
            ['A', $this->skus['race'], 'RACE-A-2'],
            ['B', $this->skus['fallback'], 'FALLBACK-B-1'],
            ['A', $this->skus['timeout'], 'TIMEOUT-A-1'],
            ['B', $this->skus['stock'], 'STOCK-B-1'],
            ['B', $this->skus['background'], 'BACKGROUND-B-1'],
            ['A', $this->skus['early'], 'EARLY-A-1'],
        ];

        $this->pdo->beginTransaction();

        try {
            $productInsert = $this->pdo->prepare(
                "INSERT INTO products (sku, name, type, price_minor, currency)
                 VALUES (:sku, :name, 'key', :price_minor, 'RUB')",
            );

            foreach ($products as $product) {
                $productInsert->execute($product);
            }

            $stockInsert = $this->pdo->prepare(
                'INSERT INTO provider_stock (provider, sku, code) VALUES (:provider, :sku, :code)',
            );
            $codePrefix = 'ITEST-' . strtoupper($this->runId) . '-';

            foreach ($stock as [$provider, $sku, $code]) {
                $stockInsert->execute([
                    'provider' => $provider,
                    'sku' => $sku,
                    'code' => $codePrefix . $code,
                ]);
            }

            $this->catalogAfterId = $this->scalarInt('SELECT COALESCE(MAX(id), 0) FROM products');
            $catalogSkuPrefix = 'ITEST-CATALOG-' . strtoupper($this->runId) . '-';
            $catalogInsert = $this->pdo->prepare(
                "INSERT INTO products (sku, name, type, price_minor, currency)
                 SELECT :sku_prefix || LPAD(series::text, 5, '0'),
                        'Integration catalog product ' || series,
                        'key', 10000 + series, 'RUB'
                 FROM generate_series(1, CAST(:fixture_size AS integer)) AS generated(series)",
            );
            $catalogInsert->bindValue('sku_prefix', $catalogSkuPrefix);
            $catalogInsert->bindValue('fixture_size', self::CATALOG_FIXTURE_SIZE, PDO::PARAM_INT);
            $catalogInsert->execute();
            $this->assertSame(
                self::CATALOG_FIXTURE_SIZE,
                $catalogInsert->rowCount(),
                'catalog fixture product count',
            );

            $catalogStockInsert = $this->pdo->prepare(
                "WITH fixture AS (
                     SELECT CAST(:sku_prefix AS text) AS sku_prefix,
                            CAST(:code_prefix AS text) AS code_prefix
                 )
                 INSERT INTO provider_stock (provider, sku, code)
                 SELECT 'A', p.sku, fixture.code_prefix || LPAD(p.id::text, 12, '0')
                 FROM products p
                 CROSS JOIN fixture
                 WHERE LEFT(p.sku, LENGTH(fixture.sku_prefix)) = fixture.sku_prefix",
            );
            $catalogStockInsert->execute([
                'sku_prefix' => $catalogSkuPrefix,
                'code_prefix' => 'ITEST-' . strtoupper($this->runId) . '-CATALOG-',
            ]);
            $this->assertSame(
                self::CATALOG_FIXTURE_SIZE,
                $catalogStockInsert->rowCount(),
                'catalog fixture stock count',
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function testCatalogLoad(): string
    {
        $payloads = array_fill(0, 50, null);
        $path = sprintf('/api/v1/catalog?limit=100&after_id=%d', $this->catalogAfterId);
        $startedAt = microtime(true);
        $responses = $this->concurrentRequests('GET', $path, $payloads);
        $duration = microtime(true) - $startedAt;

        foreach ($responses as $index => $response) {
            $this->assertSame(200, $response['status'], sprintf('catalog request #%d HTTP status', $index + 1));
            $items = $response['json']['data'] ?? null;
            $this->assertTrue(is_array($items), sprintf('catalog request #%d contains data array', $index + 1));
            $this->assertSame(100, count($items), sprintf('catalog request #%d item count', $index + 1));

            $lastId = $this->catalogAfterId;

            foreach ($items as $item) {
                $this->assertTrue(is_array($item), 'catalog item is an object');
                $id = $item['id'] ?? null;
                $this->assertTrue(is_int($id) && $id > $lastId, 'catalog uses stable ascending keyset order');
                $sku = $item['sku'] ?? null;
                $this->assertTrue(
                    is_string($sku) && str_starts_with($sku, 'ITEST-CATALOG-'),
                    'catalog page contains the isolated large fixture',
                );
                $this->assertTrue(is_array($item['price'] ?? null), 'catalog item contains price');
                $stock = $item['stock'] ?? null;
                $this->assertTrue(is_array($stock), 'catalog item contains stock');
                $this->assertSame(1, $stock['available'] ?? null, 'catalog fixture has one available code');
                $this->assertSame(true, $stock['in_stock'] ?? null, 'catalog fixture is in stock');
                $lastId = $id;
            }

            $this->assertSame(
                $lastId,
                $response['json']['meta']['next_after_id'] ?? null,
                sprintf('catalog request #%d next cursor', $index + 1),
            );
        }

        return sprintf(
            '%d SKU fixture, 50/50 pages of 100 valid in %.2fs',
            self::CATALOG_FIXTURE_SIZE,
            $duration,
        );
    }

    private function testWebhookRace(): string
    {
        $this->configureProvider('A', 'success');
        $this->configureProvider('B', 'success');
        $occurredAt = '2025-01-01T12:00:00Z';

        $sameOrder = $this->orderId('race_same');
        $sameEvent = $this->eventId('race_same');
        $this->createOrder($sameOrder, $this->skus['race']);
        $samePayload = $this->webhookPayload($sameEvent, $sameOrder, 500, 'paid', $occurredAt);
        $responses = $this->concurrentRequests('POST', '/api/v1/webhooks/payment', array_fill(0, 50, $samePayload));
        $this->assertAllHttpStatuses($responses, 200, 'duplicate webhook race');
        $this->waitForStatus($sameOrder, 'delivered');

        $this->assertSame(1, $this->count('SELECT COUNT(*) FROM payment_events WHERE event_id = :id', ['id' => $sameEvent]), 'same event_id stored once');
        $this->assertSame(1, $this->orderRelatedCount('ledger_entries', $sameOrder), 'same event creates one ledger capture');
        $this->assertSame(1, $this->orderRelatedCount('fulfillments', $sameOrder), 'same event creates one fulfillment');
        $this->assertSame(1, $this->count('SELECT COUNT(*) FROM provider_issues WHERE order_public_id = :id', ['id' => $sameOrder]), 'provider issues once');

        $uniqueOrder = $this->orderId('race_unique');
        $this->createOrder($uniqueOrder, $this->skus['race']);
        $payloads = [];

        for ($index = 1; $index <= 50; $index++) {
            $payloads[] = $this->webhookPayload(
                $this->eventId('race_unique_' . $index),
                $uniqueOrder,
                500,
                'paid',
                $occurredAt,
            );
        }

        $responses = $this->concurrentRequests('POST', '/api/v1/webhooks/payment', $payloads);
        $this->assertAllHttpStatuses($responses, 200, 'distinct webhook race');
        $this->waitForStatus($uniqueOrder, 'delivered');

        $this->assertSame(50, $this->count('SELECT COUNT(*) FROM payment_events WHERE order_public_id = :id', ['id' => $uniqueOrder]), 'all distinct events stored');
        $this->assertSame(1, $this->orderRelatedCount('ledger_entries', $uniqueOrder), 'distinct events create one ledger capture');
        $this->assertSame(1, $this->count('SELECT COUNT(*) FROM jobs WHERE aggregate_id = :id', ['id' => $uniqueOrder]), 'distinct events create one delivery job');
        $this->assertSame(1, $this->orderRelatedCount('fulfillments', $uniqueOrder), 'distinct events create one fulfillment');
        $this->assertSame(1, $this->count('SELECT COUNT(*) FROM provider_issues WHERE order_public_id = :id', ['id' => $uniqueOrder]), 'distinct events consume one provider code');

        return '100 webhooks produced two exactly-once deliveries';
    }

    private function testFallback(): string
    {
        $this->configureProvider('A', 'fail_before_issue');
        $this->configureProvider('B', 'success');
        $orderId = $this->orderId('fallback');

        $this->createOrder($orderId, $this->skus['fallback']);
        $this->sendWebhook($this->eventId('fallback'), $orderId, 1000);
        $this->waitForStatus($orderId, 'delivered');

        $this->assertSame(0, $this->providerIssueCount($orderId, 'A'), 'provider A does not issue before fallback');
        $this->assertSame(1, $this->providerIssueCount($orderId, 'B'), 'provider B performs delivery');
        $this->assertSame(1, $this->orderRelatedCount('fulfillments', $orderId), 'fallback creates one fulfillment');

        return 'A issued 0 codes, B issued 1';
    }

    private function testTimeoutRecovery(): string
    {
        $this->configureProvider('A', 'timeout_after_issue', 1500);
        $this->configureProvider('B', 'success');
        $orderId = $this->orderId('timeout');

        $this->createOrder($orderId, $this->skus['timeout']);
        $this->sendWebhook($this->eventId('timeout'), $orderId, 2500);
        $this->waitForStatus($orderId, 'delivered', 35);

        $this->assertSame(1, $this->providerIssueCount($orderId, 'A'), 'timed-out provider reserves exactly one code');
        $this->assertSame(0, $this->providerIssueCount($orderId, 'B'), 'uncertain timeout does not trigger fallback');
        $this->assertSame(1, $this->orderRelatedCount('fulfillments', $orderId), 'timeout recovery creates one fulfillment');
        $attempts = $this->count(
            "SELECT da.attempt_count
             FROM delivery_attempts da
             JOIN orders o ON o.id = da.order_id
             WHERE o.public_id = :id AND da.provider = 'A'",
            ['id' => $orderId],
        );
        $this->assertTrue($attempts >= 2, sprintf('stable request_id retried at least twice; got %d attempt(s)', $attempts));

        return sprintf('A retried %d times, B not called', $attempts);
    }

    private function testOutOfStockRecovery(): string
    {
        $this->configureProvider('A', 'out_of_stock');
        $this->configureProvider('B', 'out_of_stock');
        $orderId = $this->orderId('stock');

        $this->createOrder($orderId, $this->skus['stock']);
        $this->sendWebhook($this->eventId('stock'), $orderId, 1290);
        $this->waitForStatus($orderId, 'out_of_stock');
        $this->assertSame(0, $this->count('SELECT COUNT(*) FROM provider_issues WHERE order_public_id = :id', ['id' => $orderId]), 'out-of-stock does not reserve a code');

        $this->configureProvider('B', 'success');
        $response = $this->request('POST', '/api/v1/ops/recovery', ['order_id' => $orderId]);
        $this->assertSame(200, $response['status'], 'manual recovery HTTP status');
        $this->waitForStatus($orderId, 'delivered');
        $this->assertSame(1, $this->orderRelatedCount('fulfillments', $orderId), 'recovered order fulfilled once');

        return 'out_of_stock transitioned to delivered';
    }

    private function testBackgroundRecovery(): string
    {
        $this->configureProvider('A', 'fail_before_issue');
        $this->configureProvider('B', 'fail_before_issue');
        $orderId = $this->orderId('background');

        $this->createOrder($orderId, $this->skus['background']);
        $this->sendWebhook($this->eventId('background'), $orderId, 750);
        $this->waitForStatus($orderId, 'delivery_failed');
        $this->waitForJobStatus($orderId, 'completed', 5);
        $this->assertSame(0, $this->providerIssueCount($orderId, 'A'), 'failed provider A issued no code');
        $this->assertSame(0, $this->providerIssueCount($orderId, 'B'), 'failed provider B issued no code');

        $this->configureProvider('A', 'fail_before_issue');
        $this->configureProvider('B', 'success');
        $intervalSeconds = max(5, Env::int('RECOVERY_INTERVAL_SECONDS', 15));

        if ($intervalSeconds > 60) {
            throw new IntegrationFailure(sprintf(
                'RECOVERY_INTERVAL_SECONDS=%d is too high for the bounded integration test; use 60 or less.',
                $intervalSeconds,
            ));
        }

        $backdate = $this->pdo->prepare(
            "UPDATE orders
             SET updated_at = TIMESTAMPTZ '2000-01-01 00:00:00+00'
             WHERE public_id = :order_id",
        );
        $backdate->bindValue('order_id', $orderId);
        $backdate->execute();
        $this->assertSame(1, $backdate->rowCount(), 'background recovery fixture was backdated');

        $timeoutSeconds = max(30, ($intervalSeconds * 2) + 10);
        $this->waitForStatus($orderId, 'delivered', $timeoutSeconds);
        $this->assertSame(0, $this->providerIssueCount($orderId, 'A'), 'background retry keeps A definitive failure safe');
        $this->assertSame(1, $this->providerIssueCount($orderId, 'B'), 'background retry delivers through B');
        $this->assertSame(1, $this->orderRelatedCount('fulfillments', $orderId), 'background recovery fulfills once');

        return sprintf('recovery service requeued and delivered in at most %ds', $timeoutSeconds);
    }

    private function testWebhookOrdering(): string
    {
        $this->configureProvider('A', 'success');
        $this->configureProvider('B', 'success');
        $orderId = $this->orderId('early');

        $this->sendWebhook($this->eventId('early_failed'), $orderId, 399, 'failed', '2025-01-01T11:59:00Z');
        $this->sendWebhook($this->eventId('early_paid'), $orderId, 399, 'paid', '2025-01-01T12:00:00Z');
        $this->assertSame(
            2,
            $this->count(
                "SELECT COUNT(*) FROM payment_events
                 WHERE order_public_id = :id AND processing_state = 'pending'",
                ['id' => $orderId],
            ),
            'early webhooks remain pending before order creation',
        );

        $this->createOrder($orderId, $this->skus['early']);
        $this->waitForStatus($orderId, 'delivered');
        $this->assertSame(
            0,
            $this->count(
                "SELECT COUNT(*) FROM payment_events
                 WHERE order_public_id = :id AND processing_state = 'pending'",
                ['id' => $orderId],
            ),
            'pending events replay when order appears',
        );
        $this->assertSame(1, $this->orderRelatedCount('ledger_entries', $orderId), 'out-of-order events create one capture');

        return 'two early events reconciled in occurred_at order';
    }

    private function testReconciliationReport(): string
    {
        $paidOrder = $this->orderId('recon_paid');
        $unpaidDeliveryOrder = $this->orderId('recon_unpaid_delivery');
        $mismatchOrder = $this->orderId('recon_mismatch');
        $pendingOrder = $this->orderId('recon_pending');
        $controlledOrderIds = [$paidOrder, $unpaidDeliveryOrder, $mismatchOrder, $pendingOrder];

        try {
            $this->createOrder($paidOrder, $this->skus['reconciliation']);
            $this->createOrder($unpaidDeliveryOrder, $this->skus['reconciliation']);
            $this->createOrder($mismatchOrder, $this->skus['reconciliation']);

            $this->seedReconciliationCapture(
                $paidOrder,
                $this->eventId('recon_paid'),
                'paid',
                64_000,
            );
            $this->markDeliveredWithoutPayment($unpaidDeliveryOrder);
            $this->seedReconciliationCapture(
                $mismatchOrder,
                $this->eventId('recon_mismatch'),
                'delivered',
                64_001,
            );

            $pendingEvent = $this->eventId('recon_pending');
            $this->sendWebhook($pendingEvent, $pendingOrder, 640);
            $response = $this->request('GET', '/api/v1/ops/reconciliation?limit=500', null);
            $this->assertSame(200, $response['status'], 'reconciliation HTTP status');
            $report = $response['json']['data'] ?? null;
            $this->assertTrue(is_array($report), 'reconciliation response contains data object');

            foreach (
                ['paid_not_delivered', 'delivered_without_payment', 'ledger_mismatches', 'pending_payment_events']
                as $section
            ) {
                $this->assertTrue(is_array($report[$section] ?? null), sprintf('%s is an array', $section));
            }

            $this->assertTrue(
                $this->reportContains($report['paid_not_delivered'], $paidOrder),
                'reconciliation finds a paid order without delivery',
            );
            $this->assertTrue(
                $this->reportContains($report['delivered_without_payment'], $unpaidDeliveryOrder),
                'reconciliation finds a delivery without capture',
            );
            $this->assertTrue(
                $this->reportContains($report['ledger_mismatches'], $mismatchOrder),
                'reconciliation finds a ledger amount mismatch',
            );
            $this->assertTrue(
                $this->reportContains($report['pending_payment_events'], $pendingOrder),
                'reconciliation finds a payment event waiting for an order',
            );

            return 'all four inconsistency classes detected and fixtures removed';
        } finally {
            $this->cleanupControlledOrders($controlledOrderIds);
        }
    }

    private function seedReconciliationCapture(
        string $orderId,
        string $eventId,
        string $status,
        int $capturedMinor,
    ): void {
        if (!in_array($status, ['paid', 'delivered'], true)) {
            throw new IntegrationFailure('Unsupported reconciliation fixture status: ' . $status);
        }

        $this->pdo->beginTransaction();

        try {
            $select = $this->pdo->prepare(
                'SELECT id, amount_minor, currency FROM orders WHERE public_id = :order_id FOR UPDATE',
            );
            $select->execute(['order_id' => $orderId]);
            $order = $select->fetch();

            if (!is_array($order)) {
                throw new IntegrationFailure('Reconciliation fixture order not found: ' . $orderId);
            }

            $payload = json_encode([
                'fixture' => 'reconciliation',
                'event_id' => $eventId,
                'order_id' => $orderId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $eventInsert = $this->pdo->prepare(
                "INSERT INTO payment_events
                 (event_id, order_public_id, status, amount_minor, currency, occurred_at,
                  payload, payload_hash, processing_state, processing_note, processed_at)
                 VALUES
                 (:event_id, :order_id, 'paid', :amount_minor, :currency, NOW(),
                  CAST(:payload AS JSONB), :payload_hash, 'applied', 'integration_fixture', NOW())",
            );
            $eventInsert->execute([
                'event_id' => $eventId,
                'order_id' => $orderId,
                'amount_minor' => $order['amount_minor'],
                'currency' => trim((string) $order['currency']),
                'payload' => $payload,
                'payload_hash' => hash('sha256', $payload),
            ]);

            $ledgerInsert = $this->pdo->prepare(
                "INSERT INTO ledger_entries
                 (order_id, event_id, entry_type, amount_minor, currency, idempotency_key)
                 VALUES (:order_id, :event_id, 'capture', :amount_minor, :currency, :idempotency_key)",
            );
            $ledgerInsert->execute([
                'order_id' => $order['id'],
                'event_id' => $eventId,
                'amount_minor' => $capturedMinor,
                'currency' => trim((string) $order['currency']),
                'idempotency_key' => 'integration-fixture:' . $eventId,
            ]);

            if ($status === 'delivered') {
                $update = $this->pdo->prepare(
                    "UPDATE orders
                     SET status = 'delivered', payment_confirmed_at = NOW(), delivered_at = NOW(),
                         delivery_code = :delivery_code, version = version + 1, updated_at = NOW()
                     WHERE id = :id",
                );
                $update->execute([
                    'delivery_code' => 'ITEST-RECON-' . substr(hash('sha256', $orderId), 0, 24),
                    'id' => $order['id'],
                ]);
            } else {
                $update = $this->pdo->prepare(
                    "UPDATE orders
                     SET status = 'paid', payment_confirmed_at = NOW(),
                         version = version + 1, updated_at = NOW()
                     WHERE id = :id",
                );
                $update->execute(['id' => $order['id']]);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function markDeliveredWithoutPayment(string $orderId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE orders
             SET status = 'delivered', delivered_at = NOW(), delivery_code = :delivery_code,
                 version = version + 1, updated_at = NOW()
             WHERE public_id = :order_id",
        );
        $statement->execute([
            'delivery_code' => 'ITEST-RECON-' . substr(hash('sha256', $orderId), 0, 24),
            'order_id' => $orderId,
        ]);
        $this->assertSame(1, $statement->rowCount(), 'delivery-without-payment fixture updated');
    }

    /** @param list<array<string, mixed>> $rows */
    private function reportContains(array $rows, string $orderId): bool
    {
        foreach ($rows as $row) {
            if (is_array($row) && ($row['order_id'] ?? null) === $orderId) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $orderIds */
    private function cleanupControlledOrders(array $orderIds): void
    {
        $this->pdo->beginTransaction();

        try {
            $resetStock = $this->pdo->prepare(
                "UPDATE provider_stock ps
                 SET issued_at = NULL
                 FROM provider_issues pi
                 WHERE ps.id = pi.stock_id AND pi.order_public_id = :order_id",
            );
            $deleteFulfillments = $this->pdo->prepare(
                'DELETE FROM fulfillments WHERE order_id IN (SELECT id FROM orders WHERE public_id = :order_id)',
            );
            $deleteDiscrepancies = $this->pdo->prepare(
                'DELETE FROM provider_discrepancies WHERE order_id IN (SELECT id FROM orders WHERE public_id = :order_id)',
            );
            $deleteAttempts = $this->pdo->prepare(
                'DELETE FROM delivery_attempts WHERE order_id IN (SELECT id FROM orders WHERE public_id = :order_id)',
            );
            $deleteIssues = $this->pdo->prepare('DELETE FROM provider_issues WHERE order_public_id = :order_id');
            $deleteLedger = $this->pdo->prepare(
                'DELETE FROM ledger_entries WHERE order_id IN (SELECT id FROM orders WHERE public_id = :order_id)',
            );
            $deleteRefunds = $this->pdo->prepare(
                'DELETE FROM refunds WHERE order_id IN (SELECT id FROM orders WHERE public_id = :order_id)',
            );
            $deleteJobs = $this->pdo->prepare('DELETE FROM jobs WHERE aggregate_id = :order_id');
            $deleteItems = $this->pdo->prepare(
                'DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE public_id = :order_id)',
            );
            $deleteOrder = $this->pdo->prepare('DELETE FROM orders WHERE public_id = :order_id');
            $deleteEvents = $this->pdo->prepare('DELETE FROM payment_events WHERE order_public_id = :order_id');

            foreach ($orderIds as $orderId) {
                $parameters = ['order_id' => $orderId];
                $resetStock->execute($parameters);
                $deleteDiscrepancies->execute($parameters);
                $deleteFulfillments->execute($parameters);
                $deleteAttempts->execute($parameters);
                $deleteIssues->execute($parameters);
                $deleteRefunds->execute($parameters);
                $deleteLedger->execute($parameters);
                $deleteJobs->execute($parameters);
                $deleteItems->execute($parameters);
                $deleteOrder->execute($parameters);
                $deleteEvents->execute($parameters);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function configureProvider(string $provider, string $mode, int $timeoutMs = 1500): void
    {
        $response = $this->request('PUT', '/api/v1/dev/providers/' . $provider, [
            'mode' => $mode,
            'failure_rate' => 0,
            'timeout_rate' => 0,
            'timeout_ms' => $timeoutMs,
            'rate_limit' => 60,
            'rate_window_seconds' => 60,
        ]);
        $this->assertSame(200, $response['status'], sprintf('configure provider %s', $provider));
    }

    private function restoreProviderDefaults(bool $strict): void
    {
        $defaults = [
            'A' => ['failure_rate' => 0.1, 'timeout_rate' => 0.1],
            'B' => ['failure_rate' => 0.05, 'timeout_rate' => 0.05],
        ];
        $errors = [];

        foreach ($defaults as $provider => $rates) {
            try {
                $response = $this->request('PUT', '/api/v1/dev/providers/' . $provider, [
                    'mode' => 'random',
                    'failure_rate' => $rates['failure_rate'],
                    'timeout_rate' => $rates['timeout_rate'],
                    'timeout_ms' => 1500,
                    'rate_limit' => 60,
                    'rate_window_seconds' => 60,
                ], 3_000);

                if ($response['status'] !== 200) {
                    throw new IntegrationFailure(sprintf('HTTP %d', $response['status']));
                }
            } catch (Throwable $exception) {
                $errors[] = sprintf('%s: %s', $provider, $exception->getMessage());
            }
        }

        if ($errors === []) {
            return;
        }

        $message = 'Could not restore provider defaults (' . implode('; ', $errors) . ')';

        if ($strict) {
            throw new IntegrationFailure($message);
        }

        fwrite(STDERR, '[WARN] ' . $message . "\n");
    }

    private function createOrder(string $orderId, string $sku): void
    {
        $response = $this->request('POST', '/api/v1/orders', ['order_id' => $orderId, 'sku' => $sku]);
        $this->assertSame(201, $response['status'], 'create order HTTP status');
        $this->assertSame($orderId, $response['json']['data']['id'] ?? null, 'created order ID');
    }

    private function sendWebhook(
        string $eventId,
        string $orderId,
        int $amount,
        string $status = 'paid',
        string $occurredAt = '2025-01-01T12:00:00Z',
    ): void {
        $response = $this->request(
            'POST',
            '/api/v1/webhooks/payment',
            $this->webhookPayload($eventId, $orderId, $amount, $status, $occurredAt),
        );
        $this->assertSame(200, $response['status'], 'payment webhook HTTP status');
    }

    /** @return array<string, int|string> */
    private function webhookPayload(
        string $eventId,
        string $orderId,
        int $amount,
        string $status,
        string $occurredAt,
    ): array {
        return [
            'event_id' => $eventId,
            'order_id' => $orderId,
            'status' => $status,
            'amount' => $amount,
            'currency' => 'RUB',
            'created_at' => $occurredAt,
        ];
    }

    private function waitForStatus(string $orderId, string $expectedStatus, int $timeoutSeconds = 20): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $lastStatus = 'unknown';

        do {
            $response = $this->request('GET', '/api/v1/orders/' . rawurlencode($orderId), null, 3_000);
            $this->assertSame(200, $response['status'], 'get order HTTP status');
            $lastStatus = (string) ($response['json']['data']['status'] ?? 'missing');

            if ($lastStatus === $expectedStatus) {
                return;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        throw new IntegrationFailure(sprintf(
            'Order %s did not reach %s within %ds; latest status: %s',
            $orderId,
            $expectedStatus,
            $timeoutSeconds,
            $lastStatus,
        ));
    }

    private function waitForJobStatus(string $orderId, string $expectedStatus, int $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $lastStatus = 'missing';
        $statement = $this->pdo->prepare(
            "SELECT status FROM jobs
             WHERE aggregate_id = :order_id AND type = 'issue_order'
             ORDER BY id DESC LIMIT 1",
        );

        do {
            $statement->execute(['order_id' => $orderId]);
            $value = $statement->fetchColumn();
            $lastStatus = is_string($value) ? $value : 'missing';

            if ($lastStatus === $expectedStatus) {
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new IntegrationFailure(sprintf(
            'Order %s job did not reach %s within %ds; latest status: %s',
            $orderId,
            $expectedStatus,
            $timeoutSeconds,
            $lastStatus,
        ));
    }

    private function orderId(string $scenario): string
    {
        return self::TEST_ORDER_PREFIX . $scenario . '_' . $this->runId;
    }

    private function eventId(string $scenario): string
    {
        return 'evt_it_' . $scenario . '_' . $this->runId;
    }

    private function providerIssueCount(string $orderId, string $provider): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM provider_issues WHERE order_public_id = :id AND provider = :provider',
            ['id' => $orderId, 'provider' => $provider],
        );
    }

    private function orderRelatedCount(string $table, string $orderId): int
    {
        if (!in_array($table, ['ledger_entries', 'fulfillments'], true)) {
            throw new IntegrationFailure('Unsupported order-related assertion table: ' . $table);
        }

        return $this->count(
            sprintf('SELECT COUNT(*) FROM %s t JOIN orders o ON o.id = t.order_id WHERE o.public_id = :id', $table),
            ['id' => $orderId],
        );
    }

    /** @param array<string, int|string> $parameters */
    private function count(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $value = $statement->fetchColumn();

        if ($value === false || !is_numeric($value)) {
            throw new IntegrationFailure('Database assertion did not return a number.');
        }

        return (int) $value;
    }

    private function scalarInt(string $sql): int
    {
        $value = $this->pdo->query($sql)->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param list<array{status: int, json: array<string, mixed>, raw: string}> $responses
     */
    private function assertAllHttpStatuses(array $responses, int $expected, string $context): void
    {
        foreach ($responses as $index => $response) {
            $this->assertSame($expected, $response['status'], sprintf('%s request #%d', $context, $index + 1));
        }
    }

    private function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($actual !== $expected) {
            throw new IntegrationFailure(sprintf(
                '%s: expected %s, got %s',
                $message,
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new IntegrationFailure($message);
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{status: int, json: array<string, mixed>, raw: string}
     */
    private function request(string $method, string $path, ?array $payload, int $timeoutMs = 30_000): array
    {
        $handle = $this->createCurlHandle($method, $path, $payload, $timeoutMs);
        $raw = curl_exec($handle);

        if ($raw === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new IntegrationFailure(sprintf('%s %s failed: %s', $method, $path, $message));
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return $this->decodeResponse($status, $raw, $method . ' ' . $path);
    }

    /**
     * @param list<array<string, mixed>|null> $payloads
     * @return list<array{status: int, json: array<string, mixed>, raw: string}>
     */
    private function concurrentRequests(string $method, string $path, array $payloads): array
    {
        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($payloads as $index => $payload) {
            $handle = $this->createCurlHandle($method, $path, $payload, 30_000, $index);
            $handles[$index] = $handle;
            curl_multi_add_handle($multiHandle, $handle);
        }

        try {
            do {
                $status = curl_multi_exec($multiHandle, $running);

                if ($status !== CURLM_OK) {
                    throw new IntegrationFailure('curl_multi_exec failed: ' . curl_multi_strerror($status));
                }

                if ($running > 0) {
                    $selected = curl_multi_select($multiHandle, 1.0);

                    if ($selected === -1) {
                        usleep(1_000);
                    }
                }
            } while ($running > 0);

            $responses = [];

            foreach ($handles as $index => $handle) {
                $error = curl_error($handle);

                if ($error !== '') {
                    throw new IntegrationFailure(sprintf('%s %s request #%d failed: %s', $method, $path, $index + 1, $error));
                }

                $raw = curl_multi_getcontent($handle);
                $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $responses[$index] = $this->decodeResponse(
                    $httpStatus,
                    $raw,
                    sprintf('%s %s request #%d', $method, $path, $index + 1),
                );
            }

            ksort($responses);

            return array_values($responses);
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multiHandle, $handle);
                curl_close($handle);
            }

            curl_multi_close($multiHandle);
        }
    }

    /** @param array<string, mixed>|null $payload */
    private function createCurlHandle(
        string $method,
        string $path,
        ?array $payload,
        int $timeoutMs,
        ?int $requestIndex = null,
    ): CurlHandle {
        $handle = curl_init();

        if ($handle === false) {
            throw new IntegrationFailure('Could not initialize cURL.');
        }

        $headers = ['Accept: application/json', 'Connection: close'];
        $options = [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => min(2_000, $timeoutMs),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => false,
        ];

        if ($payload !== null) {
            try {
                $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new IntegrationFailure('Could not encode request JSON.', 0, $exception);
            }

            $options[CURLOPT_POSTFIELDS] = $body;
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        $sequence = ++$this->requestSequence;
        $requestLabel = $requestIndex === null ? (string) $sequence : $sequence . '-' . $requestIndex;
        $headers[] = sprintf('X-Request-Id: it-%s-%s', $this->runId, $requestLabel);
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        return $handle;
    }

    /** @return array{status: int, json: array<string, mixed>, raw: string} */
    private function decodeResponse(int $status, string $raw, string $context): array
    {
        try {
            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new IntegrationFailure(sprintf('%s returned invalid JSON: %s', $context, $raw), 0, $exception);
        }

        if (!is_array($json)) {
            throw new IntegrationFailure($context . ' returned a non-object JSON response.');
        }

        return ['status' => $status, 'json' => $json, 'raw' => $raw];
    }
}

try {
    $appEnv = strtolower(Env::string('APP_ENV', 'dev'));

    if (!in_array($appEnv, ['dev', 'development', 'local', 'test', 'testing'], true)) {
        throw new IntegrationFailure(sprintf(
            'Integration runner refuses to mutate data in APP_ENV=%s.',
            $appEnv,
        ));
    }

    $baseUrl = rtrim(Env::string('INTEGRATION_BASE_URL', 'http://api:8080'), '/');
    $suite = new IntegrationSuite(ConnectionFactory::create(), $baseUrl);
    exit($suite->run());
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] Integration runner could not start: ' . $exception->getMessage() . "\n");
    exit(1);
}
