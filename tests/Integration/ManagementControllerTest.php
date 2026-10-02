<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Http\Router;
use Ordinal\Service\AdministratorService as SessionService;
use Ordinal\Tests\Support\ManagementFixture;
use PHPUnit\Framework\TestCase;

/** Exercises the simplified management routes and browser authorization boundary. */
final class ManagementControllerTest extends TestCase
{
    /** Owns isolated application services. */
    private ManagementFixture $fixture;
    /** Dispatches the actual route table. */
    private Router            $router;

    /**
     * Creates provider-free application data.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->fixture = new ManagementFixture();
        $this->router = new Router($this->fixture->application);
    }

    /**
     * Releases isolated database resources.
     *
     * @return void
     */
    protected function tearDown(): void { $this->fixture->close(); }

    /**
     * Keeps the home navigation consistent with the current administrator session.
     *
     * @return void
     */
    public function testShowsAuthenticatedHomeNavigation(): void
    {
        $cookie = $this->fixture->signIn();
        $cookies = [SessionService::COOKIE_NAME => $cookie];
        $response = $this->router->dispatch('GET', '/', cookies: $cookies, isSecure: true);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Sign out', $response->body);
        self::assertStringContainsString('Audit logs', $response->body);
        self::assertStringNotContainsString('Sign in', $response->body);
        self::assertStringContainsString('no-store', $response->headers['Cache-Control']);

        $session = $this->fixture->application->administrator->authenticateSession($cookie);
        self::assertStringContainsString($session->csrfToken, $response->body);
        $this->fixture->application->administrator->signOut($session);
        $signedOut = $this->router->dispatch('GET', '/', cookies: $cookies, isSecure: true);
        self::assertSame(200, $signedOut->statusCode);
        self::assertStringContainsString('Sign in', $signedOut->body);
        self::assertStringNotContainsString('Sign out', $signedOut->body);
    }

    /**
     * Leaves the public home available without a session and ignores insecure cookies.
     *
     * @return void
     */
    public function testShowsAnonymousHomeNavigation(): void
    {
        $cookie = $this->fixture->signIn();
        foreach ([[], [SessionService::COOKIE_NAME => 'invalid'], [SessionService::COOKIE_NAME => $cookie]] as $cookies) {
            $response = $this->router->dispatch('GET', '/', cookies: $cookies);
            self::assertSame(200, $response->statusCode);
            self::assertStringContainsString('Sign in', $response->body);
            self::assertStringNotContainsString('Sign out', $response->body);
        }
        $response = $this->router->dispatch('GET', '/', cookies: [SessionService::COOKIE_NAME => 'invalid'], isSecure: true);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Sign in', $response->body);
    }

    /**
     * Protects management and requires actual HTTPS, with removed routes returning 404.
     *
     * @return void
     */
    public function testProtectsManagementAndRemovesProviderRoutes(): void
    {
        self::assertSame(400, $this->router->dispatch('GET', '/login')->statusCode);
        $response = $this->router->dispatch('GET', '/projects', isSecure: true);
        self::assertSame(303, $response->statusCode);
        self::assertSame('/login', $response->headers['Location']);
        foreach (['/devices', '/account', '/login/callback', '/login/start', '/administration'] as $path) {
            self::assertSame(404, $this->router->dispatch('GET', $path, isSecure: true)->statusCode);
        }
    }

    /**
     * Creates an independent project using its name and optional descriptive repository link.
     *
     * @return void
     */
    public function testCreatesProjectWithoutRepository(): void
    {
        $cookie = $this->fixture->signIn();
        $session = $this->fixture->application->administrator->authenticateSession($cookie);
        $cookies = [SessionService::COOKIE_NAME => $cookie];
        $response = $this->router->dispatch('POST', '/projects', http_build_query(['csrfToken' => $session->csrfToken, 'name' => 'My project', 'repositoryUrl' => '']), contentType: 'application/x-www-form-urlencoded', cookies: $cookies, isSecure: true);
        self::assertSame(303, $response->statusCode);
        $page = $this->router->dispatch('GET', $response->headers['Location'], cookies: $cookies, isSecure: true);
        self::assertSame(200, $page->statusCode);
        self::assertStringContainsString('My project', $page->body);
        self::assertStringContainsString('Generate token', $page->body);
        self::assertStringNotContainsString('Devices', $page->body);
        self::assertStringNotContainsString('provider', $page->body);
    }

    /**
     * Rejects browser mutations without a matching session token.
     *
     * @return void
     */
    public function testRejectsProjectCsrf(): void
    {
        $cookie = $this->fixture->signIn();
        $response = $this->router->dispatch('POST', '/projects', 'name=Project', contentType: 'application/x-www-form-urlencoded', cookies: [SessionService::COOKIE_NAME => $cookie], isSecure: true);
        self::assertSame(403, $response->statusCode);
        self::assertSame([], $this->fixture->application->projects->findProjects());
    }

    /**
     * Shows a generated token once and prevents browser caching of its receipt.
     *
     * @return void
     */
    public function testIssuesProjectTokenWithNoStore(): void
    {
        $cookie = $this->fixture->signIn();
        $session = $this->fixture->application->administrator->authenticateSession($cookie);
        $project = $this->fixture->application->projects->createProject('Project');
        $response = $this->router->dispatch('POST', '/projects/' . $project . '/tokens', http_build_query(['csrfToken' => $session->csrfToken, 'action' => 'create', 'name' => 'Laptop']), contentType: 'application/x-www-form-urlencoded', cookies: [SessionService::COOKIE_NAME => $cookie], isSecure: true);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('project.', $response->body);
        self::assertStringContainsString('no-store', $response->headers['Cache-Control']);
        self::assertCount(1, $this->fixture->application->tokens->findProjectTokens($project));
    }
}
