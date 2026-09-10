<?php

declare(strict_types=1);

namespace GameStore\Tests\Unit;

use GameStore\Core\Http\HttpException;
use GameStore\Core\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesNamedPathParameter(): void
    {
        $router = new Router();
        $handler = static fn (): string => 'ok';
        $router->get('/orders/{id}', $handler);
        $matched = $router->match('GET', '/orders/ord_123');

        self::assertSame('ord_123', $matched['params']['id']);
        self::assertSame('ok', ($matched['handler'])());
    }

    public function testDistinguishesMethodNotAllowed(): void
    {
        $router = new Router();
        $router->get('/health', static fn (): string => 'ok');

        try {
            $router->match('POST', '/health');
            self::fail('Expected HttpException');
        } catch (HttpException $exception) {
            self::assertSame(405, $exception->status);
        }
    }
}
