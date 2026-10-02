<?php

declare(strict_types=1);

namespace Ordinal\Http;

use Ordinal\View\TemplateRenderer;
use SensitiveParameter;
use Ordinal\Service\AccountApplication;

/** Matches explicit method/path pairs to endpoint files outside the public root. */
final class Router
{
    /** Lists endpoint paths controlled by the application rather than URL input. */
    private const array ROUTES = [
        '/' => ['GET' => 'GET/home.php'],
        '/api/projects/{projectId}/build-numbers' => ['POST' => 'POST/build-numbers.php'],
        '/api/projects/{projectId}/build-numbers/next' => ['GET' => 'GET/build-number-preview.php'],
        '/login' => ['GET' => 'GET/login.php'],
        '/login/start' => ['GET' => 'GET/login-start.php'],
        '/login/callback' => ['GET' => 'GET/login-callback.php'],
        '/account' => ['GET' => 'GET/account.php'],
        '/account/reauthenticate' => ['POST' => 'POST/reauthenticate.php'],
        '/account/logout' => ['POST' => 'POST/logout.php'],
        '/administration' => ['GET' => 'GET/administration.php', 'POST' => 'POST/administration.php'],
        '/projects' => ['GET' => 'GET/projects.php', 'POST' => 'POST/projects.php'],
        '/projects/{projectId}' => ['GET' => 'GET/project.php'],
        '/projects/{projectId}/counter' => ['GET' => 'GET/counter.php', 'POST' => 'POST/counter.php'],
        '/projects/{projectId}/history' => ['GET' => 'GET/history.php'],
        '/projects/{projectId}/logs' => ['GET' => 'GET/project-logs.php'],
        '/projects/{projectId}/history-policy' => ['POST' => 'POST/history-policy.php'],
        '/projects/{projectId}/archive' => ['POST' => 'POST/project-archive.php'],
        '/logs' => ['GET' => 'GET/instance-logs.php'],
        '/logs/cleanup' => ['GET' => 'GET/log-cleanup.php', 'POST' => 'POST/log-cleanup.php'],
        '/devices' => ['GET' => 'GET/devices.php'],
        '/devices/enroll' => ['POST' => 'POST/device-enrollment.php'],
        '/devices/revoke' => ['POST' => 'POST/device-revocation.php'],
        '/devices/policy' => ['POST' => 'POST/authentication-policy.php'],
        '/automation' => ['GET' => 'GET/automation.php', 'POST' => 'POST/automation.php'],
        '/automation/policy' => ['POST' => 'POST/automation-policy.php'],
    ];
    /** Bounds browser form bodies before parsing request fields. */
    private const int MAX_FORM_BYTES = 65_536;

    /**
     * Injects account services for route tests; ordinary routes keep lazy configuration.
     *
     * @param ?AccountApplication $accountApplication
     */
    public function __construct(
        /** Provides optional request-scoped account services to endpoint controllers. */
        private readonly ?AccountApplication $accountApplication = null,
    ) {}

    /**
     * Dispatches a registered endpoint or returns a path/method error.
     *
     * @param string $method
     * @param string $requestTarget
     * @param string $body
     * @param ?string $authorizationHeader
     * @param ?string $contentType
     * @param array $cookies
     * @param bool $isSecure
     * @return Response
     */
    public function dispatch(
        string  $method,
        string  $requestTarget,
        string  $body                = '',
        #[SensitiveParameter]
        ?string $authorizationHeader = null,
        ?string $contentType         = null,
        #[SensitiveParameter]
        array   $cookies             = [],
        bool    $isSecure            = false,
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
            if (($endpoints['POST'] ?? null) === 'POST/build-numbers.php') {
                return (new \Ordinal\Controller\BuildNumberController(new \Ordinal\Security\RuntimeAllocationAuthorizer($this->accountApplication, $isSecure)))
                    ->createMethodError($routeParameters['projectId']);
            }
            return $this->createError(
                $path,
                Response::STATUS_METHOD_NOT_ALLOWED,
                'METHOD_NOT_ALLOWED',
                'Method not allowed.',
                ['Allow' => implode(', ', array_keys($endpoints))],
            );
        }

        $query = [];
        $fields = [];
        parse_str(explode('?', $requestTarget, 2)[1] ?? '', $query);
        $query = $routeParameters + $query;
        if ($method === 'POST' && !str_starts_with($path, '/api/')) {
            if (strlen($body) > self::MAX_FORM_BYTES || strtolower(trim(explode(';', $contentType ?? '', 2)[0])) !== 'application/x-www-form-urlencoded') {
                return Response::createHtml((new TemplateRenderer())->renderError('The request is invalid.'), 400);
            }
            parse_str($body, $fields);
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
