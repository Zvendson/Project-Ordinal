<?php

declare(strict_types=1);

namespace Ordinal\Http;

/** Matches explicit method/path pairs to endpoint files outside the public root. */
final class Router
{
    /** Lists endpoint paths controlled by the application rather than URL input. */
    private const array ROUTES = [
        '/' => ['GET' => 'GET/home.php'],
    ];

    /**
     * Dispatches a registered endpoint or returns a path/method error.
     *
     * @param string $method
     * @param string $requestTarget
     * @return Response
     */
    public function dispatch(string $method, string $requestTarget): Response
    {
        $path      = explode('?', $requestTarget, 2)[0];
        $endpoints = self::ROUTES[$path] ?? null;

        if ($endpoints === null) {
            return new Response(Response::STATUS_NOT_FOUND, 'Page not found.');
        }

        if (!isset($endpoints[$method])) {
            return new Response(
                Response::STATUS_METHOD_NOT_ALLOWED,
                'Method not allowed.',
                Response::TEXT_HEADERS + ['Allow' => implode(', ', array_keys($endpoints))],
            );
        }

        return require dirname(__DIR__, 2) . '/endpoints/' . $endpoints[$method];
    }
}
