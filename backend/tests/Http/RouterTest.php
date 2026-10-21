<?php

declare(strict_types=1);

namespace Viagem\Tests\Http;

use PHPUnit\Framework\TestCase;
use Viagem\Http\HttpException;
use Viagem\Http\Request;
use Viagem\Http\Response;
use Viagem\Http\Router;

final class RouterTest extends TestCase
{
    public function testMatchesStaticRoute(): void
    {
        $router = new Router();
        $router->get('/api/health', static fn (): Response => new Response('ok'));

        self::assertSame('ok', $router->dispatch(new Request('GET', '/api/health'))->body);
    }

    public function testPassesPlaceholdersAsRouteParams(): void
    {
        $router = new Router();
        $router->get(
            '/tiles/{z:\d+}/{x:\d+}/{y:\d+}.pbf',
            static fn (Request $r): Response => new Response(
                $r->routeParam('z') . '/' . $r->routeParam('x') . '/' . $r->routeParam('y'),
            ),
        );

        self::assertSame('14/8900/5540', $router->dispatch(new Request('GET', '/tiles/14/8900/5540.pbf'))->body);
    }

    public function testDecodesUrlEncodedParams(): void
    {
        $router = new Router();
        $router->get('/api/areas/{name}', static fn (Request $r): Response => new Response($r->routeParam('name')));

        self::assertSame('Jičín', $router->dispatch(new Request('GET', '/api/areas/Ji%C4%8D%C3%ADn'))->body);
    }

    public function testRejectsPlaceholderNotMatchingConstraint(): void
    {
        $router = new Router();
        $router->get('/api/parcels/{id:\d+}', static fn (): Response => new Response());

        $this->expectException(HttpException::class);
        $router->dispatch(new Request('GET', '/api/parcels/abc'));
    }

    public function testLiteralDotMatchesOnlyDot(): void
    {
        $router = new Router();
        $router->get('/tiles/{z:\d+}.pbf', static fn (): Response => new Response());

        $this->expectException(HttpException::class);
        $router->dispatch(new Request('GET', '/tiles/14xpbf'));
    }

    public function testUnknownPathIs404(): void
    {
        try {
            (new Router())->dispatch(new Request('GET', '/nope'));
            self::fail('Expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        $router = new Router();
        $router->get('/api/health', static fn (): Response => new Response());

        try {
            $router->dispatch(new Request('POST', '/api/health'));
            self::fail('Expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
            self::assertSame(['Allow' => 'GET'], $e->headers);
        }
    }
}
