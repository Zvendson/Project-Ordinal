<?php

declare(strict_types=1);

namespace Ordinal\Http;

use Ordinal\View\TemplateRenderer;
use SensitiveParameter;

/** Matches explicit method/path pairs to endpoint files outside the public root. */
final class Router
{
    /** Lists endpoint paths controlled by the application rather than URL input. */
    private const array ROUTES = [
        '/' => ['GET' => 'GET/home.php'],
        '/api/projects/{projectId}/build-numbers' => ['POST' => 'POST/build-numbers.php'],
    ];

    /**
     * Dispatches a registered endpoint or returns a path/method error.
     *
     * @param string $method
     * @param string $requestTarget
     * @param string $body
     * @param ?string $authorizationHeader
     * @param ?string $contentType
     * @return Response
     */
    public function dispatch(
        string  $method,
        string  $requestTarget,
        string  $body                = '',
        #[SensitiveParameter]
        ?string $authorizationHeader = null,
        ?string $contentType         = null,
    ): Response
    {
        $path            = explode('?', $requestTarget, 2)[0];
        $route           = $this->findRoute($path);
        $endpoints       = $route['endpoints'] ?? null;
        $routeParameters = $route['parameters'] ?? [];

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
     * Matches registered patterns while keeping endpoint filenames independent of input.
     *
     * @param string $path
     * @return array
     */
    private function findRoute(string $path): array
    {
        foreach (self::ROUTES as $routePath => $endpoints) {
            if ($routePath === $path && !str_contains($routePath, '{projectId}')) {
                return ['endpoints' => $endpoints, 'parameters' => []];
            }
            if (str_contains($routePath, '{projectId}')) {
                $pattern = str_replace('\\{projectId\\}', '([^/]+)', preg_quote($routePath, '~'));
                if (preg_match('~^' . $pattern . '$~D', $path, $matches) === 1) {
                    return ['endpoints' => $endpoints, 'parameters' => ['projectId' => $matches[1]]];
                }
            }
        }
        return [];
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
