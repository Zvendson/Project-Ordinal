<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Http;

use Ordinal\Http\Router;
use PHPUnit\Framework\TestCase;

/** Verifies explicit route matching and method restrictions. */
final class RouterTest extends TestCase
{
    /**
     * Dispatches the home endpoint through its controller.
     *
     * @return void
     */
    public function testDispatchesHomeEndpoint(): void
    {
        $response = (new Router())->dispatch('GET', '/');

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('<h1>Project: Ordinal</h1>', $response->body);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
    }

    /**
     * Matches only the path while ignoring query parameters.
     *
     * @return void
     */
    public function testIgnoresQueryParameters(): void
    {
        self::assertSame(200, (new Router())->dispatch('GET', '/?page=home')->statusCode);
    }

    /**
     * Rejects a method that has no endpoint for an existing path.
     *
     * @return void
     */
    public function testRejectsUnsupportedMethods(): void
    {
        foreach (['POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'get'] as $method) {
            $response = (new Router())->dispatch($method, '/');

            self::assertSame(405, $response->statusCode);
            self::assertStringContainsString('Method not allowed.', $response->body);
            self::assertSame('GET', $response->headers['Allow']);
        }
    }

    /**
     * Prevents unknown URLs from selecting application or local files.
     *
     * @return void
     */
    public function testRejectsUnknownAndPrivatePaths(): void
    {
        foreach (['/missing', '/src/Http/Router.php', '/endpoints/GET/home.php',
            '/docs/requirements.md', '/tests/Unit/RuntimeTest.php', '/composer.json',
            '/.env', '/../README.md', '/%2e%2e/README.md', '//', 'https://example.com/'] as $path) {
            $response = (new Router())->dispatch('GET', $path);

            self::assertSame(404, $response->statusCode, $path);
            self::assertStringContainsString('Page not found.', $response->body);
        }
    }

    /**
     * Returns JSON for unknown API routes, without pretending a project was looked up.
     *
     * @return void
     */
    public function testReturnsJsonForUnknownApiRoutes(): void
    {
        foreach (['/api', '/api/missing'] as $path) {
            $response = (new Router())->dispatch('GET', $path);
            self::assertSame(404, $response->statusCode);
            self::assertSame('application/json; charset=UTF-8', $response->headers['Content-Type']);
            self::assertSame('NOT_FOUND', json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        }
    }
}
