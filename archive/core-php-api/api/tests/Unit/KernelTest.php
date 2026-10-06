<?php

declare(strict_types=1);

namespace SmartHeart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmartHeart\App;
use SmartHeart\Config;
use SmartHeart\Http\HttpError;
use SmartHeart\Http\Kernel;
use SmartHeart\Http\Request;
use SmartHeart\Http\Response;
use SmartHeart\Http\Router;
use SmartHeart\Infra\Secrets;

final class KernelTest extends TestCase
{
    private const BASE = 'https://api.smartheart.example.in/problems';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testUnknownPathIsProblemJson(): void
    {
        $response = (new Kernel(new Router(), self::BASE))->handle(new Request('GET', '/nope'));

        self::assertSame(404, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);
        self::assertSame(self::BASE . '/not-found', $response->body['type']);
        self::assertSame(404, $response->body['status']);
        self::assertMatchesRegularExpression(self::UUID, $response->body['request_id']);
        self::assertSame($response->body['request_id'], $response->headers['X-Request-Id']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    public function testValidationErrorsAreListed(): void
    {
        $router = new Router();
        $router->add('POST', '/x', static fn() => throw HttpError::validation([['field' => 'sbp', 'code' => 'out_of_range']]));
        $response = (new Kernel($router, self::BASE))->handle(new Request('POST', '/x'));

        self::assertSame(422, $response->status);
        self::assertSame([['field' => 'sbp', 'code' => 'out_of_range']], $response->body['errors']);
    }

    public function testUnexpectedExceptionIsLoggedButNotShown(): void
    {
        $router = new Router();
        $router->add('GET', '/boom', static fn() => throw new RuntimeException('secret table name'));
        $logged = [];
        $response = (new Kernel($router, self::BASE, function (string $line) use (&$logged) {
            $logged[] = $line;
        }))->handle(new Request('GET', '/boom'));

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('secret', $response->encodedBody());
        self::assertCount(1, $logged);
        self::assertStringContainsString('secret table name', $logged[0]);
        self::assertStringContainsString($response->headers['X-Request-Id'], $logged[0]);
    }

    public function testJsonBodyRules(): void
    {
        $router = new Router();
        $router->add('POST', '/echo', static fn(Request $r) => Response::json($r->json()));
        $kernel = new Kernel($router, self::BASE);
        $post = static fn(string $type, string $body) => $kernel->handle(new Request('POST', '/echo', [], ['content-type' => $type], $body));

        self::assertSame(['a' => 1], $post('application/json; charset=utf-8', '{"a":1}')->body);
        self::assertSame(415, $post('text/plain', '{"a":1}')->status);
        self::assertSame(400, $post('application/json', '{"a":')->status);
        self::assertSame(400, $post('application/json', '[1,2]')->status);
    }

    public function testHealthIs503WhenTheDatabaseIsUnreachable(): void
    {
        $config = new Config('testing', '9.9.9', self::BASE, '127.0.0.1', 3306, 'smart_heart', 'root', '', '/nonexistent/mysql.sock');
        $response = App::kernel($config, Secrets::random())->handle(new Request('GET', '/health'));

        self::assertSame(503, $response->status);
        self::assertSame(['status' => 'degraded', 'db' => 'unreachable', 'version' => '9.9.9'], $response->body);
    }
}
