<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Http\Router;
use Ordinal\Service\BrowserSessionService;
use Ordinal\Tests\Support\AccountFixture;
use PHPUnit\Framework\TestCase;

/** Verifies JSON preview, CSRF-protected forms, permissions, escaped history and cleanup confirmation. */
final class ProjectControllerTest extends TestCase
{
    /** Shares this test's isolated account setup. */
    private AccountFixture $fixture;
    /**
     * Creates an isolated migrated application.
     *
     * @return void
     */
    protected function setUp(): void { $this->fixture = new AccountFixture(); }
    /**
     * Removes only the fixture schema.
     *
     * @return void
     */
    protected function tearDown(): void { $this->fixture->close(); }

    /**
     * Requires HTTPS and browser administration, returns the specified JSON and never consumes a number.
     *
     * @return void
     */
    public function testProtectsJsonPreviewAndCounterForms(): void
    {
        $app = $this->fixture->application;
        $secret = $this->fixture->signIn();
        $session = $app->sessions->authenticate($secret);
        $project = $app->projects->createProject($session, 1, '77', '<Project>');
        $cookies = [BrowserSessionService::COOKIE_NAME => $secret];
        $router = new Router($app);
        $path = '/api/projects/' . $project . '/build-numbers/next';
        foreach ([null, 'Bearer automation.1.' . str_repeat('a', 64)] as $header) {
            $denied = $router->dispatch('GET', $path, authorizationHeader: $header, isSecure: true);
            self::assertSame(401, $denied->statusCode);
            self::assertSame('INVALID_AUTHENTICATION', json_decode($denied->body, true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        }
        self::assertSame(401, $router->dispatch('GET', $path, cookies: $cookies)->statusCode);
        $response = $router->dispatch('GET', $path, cookies: $cookies, isSecure: true);
        self::assertSame(200, $response->statusCode);
        self::assertSame(['projectId' => $project, 'nextBuildNumber' => 1, 'isExhausted' => false], json_decode($response->body, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertSame(405, $router->dispatch('POST', $path)->statusCode);
        self::assertSame(0, (int) $this->fixture->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        $controlPath = '/projects/' . $project . '/counter';
        $page = $router->dispatch('GET', $controlPath, cookies: $cookies, isSecure: true);
        self::assertSame(200, $page->statusCode);
        self::assertStringContainsString('&lt;Project&gt;', $page->body);
        self::assertStringContainsString('previously allocated numbers', $page->body);
        $fields = ['action' => 'edit', 'nextBuildNumber' => '0'];
        self::assertSame(403, $router->dispatch('POST', $controlPath, http_build_query($fields), contentType: 'application/x-www-form-urlencoded', cookies: $cookies, isSecure: true)->statusCode);
        $fields['csrfToken'] = $session->csrfToken;
        self::assertSame(303, $router->dispatch('POST', $controlPath, http_build_query($fields), contentType: 'application/x-www-form-urlencoded', cookies: $cookies, isSecure: true)->statusCode);
        $fields['nextBuildNumber'] = '1e3';
        self::assertSame(400, $router->dispatch('POST', $controlPath, http_build_query($fields), contentType: 'application/x-www-form-urlencoded', cookies: $cookies, isSecure: true)->statusCode);
        $userCookies = [BrowserSessionService::COOKIE_NAME => $this->fixture->signIn('9')];
        $this->fixture->role = 30;
        self::assertSame(403, $router->dispatch('GET', $path, cookies: $userCookies, isSecure: true)->statusCode);
        self::assertSame(403, $router->dispatch('GET', $controlPath, cookies: $userCookies, isSecure: true)->statusCode);
    }

    /**
     * Protects history/log scopes, cleanup preview/confirmation, archive/reactivation and visibility forms.
     *
     * @return void
     */
    public function testRoutesHistoryCleanupAndProjectSettings(): void
    {
        $app = $this->fixture->application;
        $secret = $this->fixture->signIn();
        $session = $app->sessions->authenticate($secret);
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        $cookies = [BrowserSessionService::COOKIE_NAME => $secret];
        $router = new Router($app);
        foreach (['/projects/' . $project . '/history', '/projects/' . $project . '/logs', '/logs', '/logs/cleanup?beforeDate=' . gmdate('Y-m-d')] as $path) {
            self::assertSame(401, $router->dispatch('GET', $path, isSecure: true)->statusCode);
            self::assertSame(200, $router->dispatch('GET', $path, cookies: $cookies, isSecure: true)->statusCode);
        }
        self::assertSame(400, $router->dispatch('GET', '/logs/cleanup?beforeDate=2026-02-30', cookies: $cookies, isSecure: true)->statusCode);
        $fields = ['csrfToken' => $session->csrfToken, 'beforeDate' => gmdate('Y-m-d'), 'isConfirmed' => '1'];
        self::assertSame(303, $router->dispatch('POST', '/logs/cleanup', http_build_query($fields), contentType: 'application/x-www-form-urlencoded', cookies: $cookies, isSecure: true)->statusCode);
        $fields = ['csrfToken' => $session->csrfToken, 'isVisible' => '1'];
        self::assertSame(303, $router->dispatch('POST', '/projects/' . $project . '/history-policy', http_build_query($fields), contentType: 'application/x-www-form-urlencoded', cookies: $cookies, isSecure: true)->statusCode);
        foreach (['1', '0'] as $isArchived) {
            $fields = ['csrfToken' => $session->csrfToken, 'isArchived' => $isArchived];
            self::assertSame(303, $router->dispatch('POST', '/projects/' . $project . '/archive', http_build_query($fields), contentType: 'application/x-www-form-urlencoded', cookies: $cookies, isSecure: true)->statusCode);
            self::assertSame(200, $router->dispatch('GET', '/projects/' . $project, cookies: $cookies, isSecure: true)->statusCode);
        }
        $userCookies = [BrowserSessionService::COOKIE_NAME => $this->fixture->signIn('9')];
        $this->fixture->role = 30;
        self::assertSame(200, $router->dispatch('GET', '/projects/' . $project . '/history', cookies: $userCookies, isSecure: true)->statusCode);
        self::assertSame(403, $router->dispatch('GET', '/logs', cookies: $userCookies, isSecure: true)->statusCode);
        self::assertSame(403, $router->dispatch('GET', '/projects/' . $project . '/logs', cookies: $userCookies, isSecure: true)->statusCode);
    }

    /**
     * A real valid CI token cannot authenticate history, preview, controls or cleanup even with copied CSRF fields.
     *
     * @return void
     */
    public function testRejectsCiCredentialsOnAllAdministrativeAndHistoryRoutes(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        $token = $app->automation->createToken($session, $project, 'CI');
        $router = new Router($app);
        foreach (['/projects/' . $project . '/history', '/projects/' . $project . '/logs', '/projects/' . $project . '/counter', '/logs', '/logs/cleanup', '/api/projects/' . $project . '/build-numbers/next'] as $path) {
            self::assertSame(401, $router->dispatch('GET', $path, authorizationHeader: 'Bearer ' . $token['token'], isSecure: true)->statusCode);
        }
        $fields = http_build_query(['csrfToken' => $session->csrfToken, 'action' => 'edit', 'nextBuildNumber' => '0', 'isArchived' => '1', 'isVisible' => '1', 'beforeDate' => gmdate('Y-m-d'), 'isConfirmed' => '1']);
        foreach (['/projects/' . $project . '/counter', '/projects/' . $project . '/archive', '/projects/' . $project . '/history-policy', '/logs/cleanup'] as $path) {
            self::assertSame(401, $router->dispatch('POST', $path, $fields, 'Bearer ' . $token['token'], 'application/x-www-form-urlencoded', [], true)->statusCode);
        }
        self::assertSame(1, (int) $this->fixture->connection->query('SELECT next_build_number FROM projects')->fetchColumn());
        self::assertNull($this->fixture->connection->query('SELECT archived_at FROM projects')->fetchColumn());
    }
}
