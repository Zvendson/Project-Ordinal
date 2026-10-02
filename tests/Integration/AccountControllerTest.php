<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Configuration\SecurityConfigurationLoader;
use Ordinal\Controller\AccountController;
use Ordinal\Database\MigrationRunner;
use Ordinal\Http\HttpClient;
use Ordinal\Http\HttpResponse;
use Ordinal\Http\Router;
use Ordinal\Service\AccountApplication;
use Ordinal\Service\BrowserSessionService;
use Ordinal\Service\LoginService;
use Ordinal\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Verifies account browser flows, CSRF, cookie attributes, and safe upstream error responses. */
final class AccountControllerTest extends TestCase
{
    /** Holds the guarded PostgreSQL connection. */
    private PDO                $connection;
    /** Names the random schema used only by this test. */
    private string             $schemaName;
    /** Injects the real account services into browser handlers. */
    private AccountApplication $application;
    /** Controls the upstream failure fixture. */
    private bool               $isProviderUnavailable = false;

    /**
     * Creates migrated fixtures and a fake OAuth provider.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection = TestDatabase::createConnection();
        $this->schemaName = 'account_controller_test_' . bin2hex(random_bytes(8));
        $this->connection->exec('CREATE SCHEMA ' . $this->schemaName);
        $this->connection->exec('SET search_path TO ' . $this->schemaName);
        (new MigrationRunner($this->connection))->applyMigrations(dirname(__DIR__, 2) . '/database/migrations');
        $client = $this->createStub(HttpClient::class);
        $client->method('request')->willReturnCallback(
            /**
             * Returns a deterministic verified login or a provider outage.
             *
             * @param string $method
             * @param string $url
             * @param array $headers
             * @param array $form
             * @return HttpResponse
             */
            function (string $method, string $url, array $headers, array $form): HttpResponse {
                if ($this->isProviderUnavailable) {
                    return new HttpResponse(503, '{"message":"private-upstream-secret"}');
                }
                $data = match (true) {
                    $method === 'POST' => ['access_token' => 'fake-access', 'refresh_token' => 'fake-refresh', 'token_type' => 'bearer', 'expires_in' => 3600],
                    str_ends_with($url, '/user') => ['id' => 8, 'username' => 'alice', 'name' => '<Alice>', 'state' => 'active'],
                    default => ['id' => 77, 'path_with_namespace' => 'team/repository'],
                };
                return new HttpResponse(200, json_encode($data, JSON_THROW_ON_ERROR));
            },
        );
        $configuration = SecurityConfigurationLoader::load(['encryptionKey' => str_repeat('ab', 32), 'bootstrapConnection' => 'primary', 'bootstrapUserId' => '8', 'connections' => ['primary' => ['kind' => 'gitlab', 'serverUrl' => 'https://gitlab.example', 'clientId' => 'app', 'clientSecret' => 'fake-client-secret', 'redirectUri' => 'https://ordinal.example/login/callback']]]);
        $this->application = new AccountApplication($this->connection, $configuration, $client);
    }

    /**
     * Drops only this test's schema.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (isset($this->connection, $this->schemaName)) {
            $this->connection->exec('DROP SCHEMA IF EXISTS ' . $this->schemaName . ' CASCADE');
        }
    }

    /**
     * Completes provider verification and returns the opaque browser credential.
     *
     * @return string
     */
    private function signIn(): string
    {
        $attempt = $this->application->login->beginLogin(1);
        parse_str((string) parse_url($attempt['authorizationUrl'], PHP_URL_QUERY), $query);
        return $this->application->login->completeLogin($query['state'], 'code', $attempt['browserSecret']);
    }

    /**
     * Rejects account flows over untrusted plain HTTP before storing login attempts.
     *
     * @return void
     */
    public function testRequiresHttps(): void
    {
        $response = (new AccountController($this->application))->handle('startLogin', ['connectionId' => '1'], [], [], false);
        self::assertSame(400, $response->statusCode);
        self::assertSame(0, (int) $this->connection->query('SELECT count(*) FROM oauth_attempts')->fetchColumn());
    }

    /**
     * Checks state cookies, callback credential rotation, and secure session cookie attributes.
     *
     * @return void
     */
    public function testCompletesLoginWithSecureCookies(): void
    {
        $controller = new AccountController($this->application);
        $start = $controller->handle('startLogin', ['connectionId' => '1'], [], [], true);
        self::assertSame(303, $start->statusCode);
        self::assertStringContainsString('Secure; HttpOnly; SameSite=Lax', $start->headers['Set-Cookie']);
        parse_str((string) parse_url($start->headers['Location'], PHP_URL_QUERY), $query);
        preg_match('/^' . preg_quote(LoginService::COOKIE_NAME, '/') . '=([a-f0-9]{64})/', $start->headers['Set-Cookie'], $matches);
        $callback = $controller->handle('completeLogin', ['state' => $query['state'], 'code' => 'code'], [], [LoginService::COOKIE_NAME => $matches[1]], true);
        self::assertSame(303, $callback->statusCode);
        self::assertSame('/account', $callback->headers['Location']);
        self::assertCount(2, $callback->headers['Set-Cookie']);
        self::assertStringStartsWith(BrowserSessionService::COOKIE_NAME . '=', $callback->headers['Set-Cookie'][0]);
        self::assertStringNotContainsString('fake-access', json_encode($callback->headers));
        self::assertStringNotContainsString('fake-client-secret', $callback->body);
    }

    /**
     * Rejects CSRF before an administrative mutation can change policy.
     *
     * @return void
     */
    public function testRejectsMissingCsrfToken(): void
    {
        $secret = $this->signIn();
        $controller = new AccountController($this->application);
        $response = $controller->handle('saveAdministration', [], ['action' => 'sessionLimits', 'idleMinutes' => '1', 'absoluteMinutes' => '2'], [BrowserSessionService::COOKIE_NAME => $secret], true);
        self::assertSame(403, $response->statusCode);
        self::assertSame(30, (int) $this->connection->query('SELECT browser_idle_minutes FROM instance_settings')->fetchColumn());
    }

    /**
     * Validates fields only after an authenticated CSRF-protected administrator form is accepted.
     *
     * @return void
     */
    public function testAcceptsSessionPolicyFormAndRejectsFractionalValues(): void
    {
        $secret = $this->signIn();
        $session = $this->application->sessions->authenticate($secret);
        $controller = new AccountController($this->application);
        $fields = ['action' => 'sessionLimits', 'csrfToken' => $session->csrfToken, 'idleMinutes' => '10', 'absoluteMinutes' => '120'];
        self::assertSame(303, $controller->handle('saveAdministration', [], $fields, [BrowserSessionService::COOKIE_NAME => $secret], true)->statusCode);
        $fields['idleMinutes'] = '1.5';
        self::assertSame(400, $controller->handle('saveAdministration', [], $fields, [BrowserSessionService::COOKIE_NAME => $secret], true)->statusCode);
    }

    /**
     * Escapes identity names and provides simple navigation and protected forms.
     *
     * @return void
     */
    public function testRendersEscapedAccountAndAdministrationPages(): void
    {
        $secret = $this->signIn();
        $controller = new AccountController($this->application);
        $cookies = [BrowserSessionService::COOKIE_NAME => $secret];
        $response = $controller->handle('showAccount', [], [], $cookies, true);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('&lt;Alice&gt;', $response->body);
        self::assertStringNotContainsString('<Alice>', $response->body);
        $admin = $controller->handle('showAdministration', [], [], $cookies, true);
        self::assertSame(200, $admin->statusCode);
        self::assertStringContainsString('csrfToken', $admin->body);
        self::assertStringNotContainsString('fake-client-secret', $admin->body);
    }

    /**
     * Returns a safe temporary error when provider verification fails during a callback.
     *
     * @return void
     */
    public function testMapsProviderOutageTo503WithoutCreatingIdentity(): void
    {
        $attempt = $this->application->login->beginLogin(1);
        parse_str((string) parse_url($attempt['authorizationUrl'], PHP_URL_QUERY), $query);
        $this->isProviderUnavailable = true;
        $response = (new AccountController($this->application))->handle('completeLogin', ['state' => $query['state'], 'code' => 'code'], [], [LoginService::COOKIE_NAME => $attempt['browserSecret']], true);
        self::assertSame(503, $response->statusCode);
        self::assertStringNotContainsString('private-upstream-secret', $response->body);
        self::assertSame(0, (int) $this->connection->query('SELECT count(*) FROM users')->fetchColumn());
    }



    /**
     * Rejects a provider-denied callback and consumes its state so it cannot be replayed.
     *
     * @return void
     */
    public function testConsumesDeniedCallbackState(): void
    {
        $attempt = $this->application->login->beginLogin(1);
        parse_str((string) parse_url($attempt['authorizationUrl'], PHP_URL_QUERY), $query);
        $response = (new AccountController($this->application))->handle('completeLogin', ['state' => $query['state'], 'error' => 'access_denied'], [], [LoginService::COOKIE_NAME => $attempt['browserSecret']], true);
        self::assertSame(401, $response->statusCode);
        self::assertSame(0, (int) $this->connection->query('SELECT count(*) FROM oauth_attempts')->fetchColumn());
    }




}
