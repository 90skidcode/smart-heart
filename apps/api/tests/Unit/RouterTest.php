<?php

declare(strict_types=1);

namespace SmartHeart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmartHeart\Http\HttpError;
use SmartHeart\Http\Request;
use SmartHeart\Http\Response;
use SmartHeart\Http\Router;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->add('GET', '/participants/{participantId}/visits/{visitCode}', static fn(Request $r, array $p) => Response::json($p));
        $this->router->add('PUT', '/participants/{participantId}/visits/{visitCode}', static fn() => Response::noContent());
    }

    public function testCapturesPathParameters(): void
    {
        [$handler, $params] = $this->router->match('GET', '/participants/48/visits/D30');
        self::assertSame(['participantId' => '48', 'visitCode' => 'D30'], $params);
        self::assertSame(['participantId' => '48', 'visitCode' => 'D30'], $handler(new Request('GET', '/'), $params)->body);
    }

    public function testParametersDoNotSpanSegments(): void
    {
        $this->expectExceptionObject(HttpError::notFound());
        $this->router->match('GET', '/participants/48/extra/visits/D30');
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        try {
            $this->router->match('DELETE', '/participants/48/visits/D30');
            self::fail('Expected 405');
        } catch (HttpError $e) {
            self::assertSame(405, $e->status);
            self::assertSame(['Allow' => 'GET, PUT'], $e->headers);
        }
    }

    public function testHeadIsServedByGet(): void
    {
        self::assertSame(['participantId' => '1', 'visitCode' => 'BL'], $this->router->match('HEAD', '/participants/1/visits/BL')[1]);
    }

    public function testRegexCharactersInPatternsAreLiteral(): void
    {
        $router = new Router();
        $router->add('GET', '/a.b', static fn() => Response::noContent());
        $router->match('GET', '/a.b');
        $this->expectException(HttpError::class);
        $router->match('GET', '/aXb');
    }
}
