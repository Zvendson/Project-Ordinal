<?php

declare(strict_types=1);

namespace Ordinal\Http;

use Ordinal\View\TemplateRenderer;

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
            return $this->createError($path, Response::STATUS_NOT_FOUND, 'NOT_FOUND', 'Page not found.');
        }

        if (!isset($endpoints[$method])) {
            return $this->createError(
                $path,
                Response::STATUS_METHOD_NOT_ALLOWED,
                'METHOD_NOT_ALLOWED',
                'Method not allowed.',
                ['Allow' => implode(', ', array_keys($endpoints))],
            );
        }

        return require dirname(__DIR__, 2) . '/endpoints/' . $endpoints[$method];
    }

    /**
     * Chooses a JSON API error or an escaped browser error for the requested path.
     *
     * @param string $path
     * @param int $statusCode
     * @param string $code
     * @param string $message
     * @param array $headers
     * @return Response
     */
    private function createError(
        string $path,
        int    $statusCode,
        string $code,
        string $message,
        array  $headers = [],
    ): Response {
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return ApiError::createResponse($code, $headers);
        }

        return Response::createHtml((new TemplateRenderer())->renderError($message), $statusCode, $headers);
    }
}
