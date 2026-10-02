<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Configuration\SecurityConfigurationLoader;
use Ordinal\Database\MigrationRunner;
use Ordinal\Http\HttpClient;
use Ordinal\Http\HttpResponse;
use Ordinal\Model\BrowserSession;
use Ordinal\Security\AuthenticationException;
use Ordinal\Service\AccountApplication;
use Ordinal\Service\AccountException;
use Ordinal\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Exercises CI token policy, protected forms, authorized replay, and concurrency on PostgreSQL. */
final class AutomationServiceTest extends TestCase
{
    /** Holds this test's guarded database connection. */
    private PDO                $connection;
    /** Names the isolated test schema. */
    private string             $schemaName;
    /** Composes the real account services with a fake provider transport. */
    private AccountApplication $application;
    /** Controls the fake provider's effective role. */
    private int                $role = 40;
    /** Records outbound refresh calls. */
    private int                $refreshCalls = 0;
    /** Simulates provider verification unavailability. */
    private bool               $isProviderUnavailable = false;
    /** Simulates an upstream refresh credential that has been revoked. */
    private bool               $isRefreshRejected = false;
    /** Counts provider calls so local rejection order can be asserted. */
    private int                $providerCalls = 0;

    /**
     * Creates an isolated schema, external-style configuration, and deterministic provider responses.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection = TestDatabase::createConnection();
        $this->schemaName = 'automation_test_' . bin2hex(random_bytes(8));
        $this->connection->exec('CREATE SCHEMA ' . $this->schemaName);
        $this->connection->exec('SET search_path TO ' . $this->schemaName);
        (new MigrationRunner($this->connection))->applyMigrations(dirname(__DIR__, 2) . '/database/migrations');
        $client = $this->createStub(HttpClient::class);
        $client->method('request')->willReturnCallback(
            /**
             * Models provider identity, OAuth rotation, and inherited repository roles.
             *
             * @param string $method
             * @param string $url
             * @param array $headers
             * @param array $form
             * @return HttpResponse
             */
            function (string $method, string $url, array $headers, array $form): HttpResponse {
                $this->providerCalls++;
                if ($this->isProviderUnavailable) {
                    return new HttpResponse(503, '{}');
                }
                if ($method === 'POST') {
                    $isRefresh = ($form['grant_type'] ?? '') === 'refresh_token';
                    if ($isRefresh && $this->isRefreshRejected) {
                        return new HttpResponse(400, '{"error":"invalid_grant"}');
                    }
                    if ($isRefresh) {
                        $this->refreshCalls++;
                    }
                    $id = $isRefresh ? substr($form['refresh_token'], strlen('refresh-')) : $form['code'];
                    $data = ['access_token' => 'access-' . $id, 'refresh_token' => 'refresh-' . $id, 'token_type' => 'bearer', 'expires_in' => 3600];
                } elseif (str_ends_with($url, '/user')) {
                    $id = substr($headers['Authorization'], strlen('Bearer access-'));
                    $data = ['id' => (int) $id, 'username' => 'user-' . $id, 'name' => 'User ' . $id, 'state' => 'active'];
                } elseif (str_contains($url, '/members/all/')) {
                    $data = ['id' => (int) substr($url, strrpos($url, '/') + 1), 'access_level' => $this->role, 'state' => 'active', 'expires_at' => null];
                } else {
                    $data = ['id' => 77, 'path_with_namespace' => 'team/repository'];
                }
                return new HttpResponse(200, json_encode($data, JSON_THROW_ON_ERROR));
            },
        );
        $configuration = SecurityConfigurationLoader::load([
            'encryptionKey' => str_repeat('ab', 32), 'bootstrapConnection' => 'primary', 'bootstrapUserId' => '8',
            'connections' => [
                'primary' => ['kind' => 'gitlab', 'serverUrl' => 'https://gitlab.example', 'clientId' => 'app', 'clientSecret' => 'fake-secret', 'redirectUri' => 'https://ordinal.example/login/callback'],
                'secondary' => ['kind' => 'gitlab', 'serverUrl' => 'https://other.example', 'clientId' => 'other', 'clientSecret' => 'fake-other-secret', 'redirectUri' => 'https://ordinal.example/login/callback'],
            ],
        ]);
        $this->application = new AccountApplication($this->connection, $configuration, $client);
    }

    /**
     * Drops only this test's random schema.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (isset($this->connection, $this->schemaName)) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            $this->connection->exec('DROP SCHEMA IF EXISTS ' . $this->schemaName . ' CASCADE');
        }
    }

    /**
     * Performs the actual state/verifier flow against mocked provider responses.
     *
     * @param string $providerUserId
     * @param int $connectionId
     * @return string
     */
    private function signIn(string $providerUserId = '8', int $connectionId = 1): string
    {
        $request = $this->application->login->beginLogin($connectionId);
        parse_str((string) parse_url($request['authorizationUrl'], PHP_URL_QUERY), $query);
        return $this->application->login->completeLogin($query['state'], $providerUserId, $request['browserSecret']);
    }

    /**
     * Signs in and returns the current server-side browser session.
     *
     * @return BrowserSession
     */
    private function signInAdministrator(): BrowserSession
    {
        return $this->application->sessions->authenticate($this->signIn());
    }

    /**
     * Stores a hash, fixed 90-day expiration, named stable identity, and CI attribution.
     *
     * @return void
     */
    public function testCreatesNamedProjectToken(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $token = $this->application->automation->createToken($session, $project, 'Release CI');
        $row = $this->connection->query('SELECT * FROM automation_tokens')->fetch(PDO::FETCH_ASSOC);
        $parsed = \Ordinal\Security\AutomationToken::parseToken($token['token']);
        self::assertSame(hash('sha256', $parsed['secret']), $row['secret_hash']);
        self::assertSame('Release CI', $row['name']);
        self::assertSame(90, (int) $this->connection->query('SELECT extract(day FROM expires_at - created_at) FROM automation_tokens')->fetchColumn());
        self::assertSame(1, $this->allocate($project, $token['token'], 1));
        $allocation = $this->connection->query('SELECT * FROM allocations')->fetch(PDO::FETCH_ASSOC);
        self::assertSame($token['id'], $allocation['automation_token_id']);
        self::assertNull($allocation['user_id']);
        self::assertSame('automation', $allocation['caller_kind']);
        self::assertStringNotContainsString($parsed['secret'], json_encode($this->application->automation->getProjectTokens($session, $project), JSON_THROW_ON_ERROR));
    }

    /**
     * Keeps token ID and request history across rename and secret rotation.
     *
     * @return void
     */
    public function testRotatesSecretAndPreservesReplayIdentity(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $token = $this->application->automation->createToken($session, $project, 'CI');
        self::assertSame(1, $this->allocate($project, $token['token'], 1));
        $this->application->automation->renameToken($session, $token['id'], 'Release CI');
        $this->application->automation->savePolicy($session, 7, false);
        $replacement = $this->application->automation->rotateToken($session, $token['id']);
        self::assertSame($token['id'], $replacement['id']);
        self::assertNotSame($token['token'], $replacement['token']);
        self::assertSame(401, $this->request($project, $token['token'], 1)->statusCode);
        self::assertSame(1, $this->allocate($project, $replacement['token'], 1));
        self::assertSame(2, $this->allocate($project, $replacement['token'], 2));
        self::assertSame('Release CI', $this->connection->query('SELECT name FROM automation_tokens')->fetchColumn());
        self::assertSame(7, (int) $this->connection->query('SELECT extract(day FROM expires_at - rotated_at) FROM automation_tokens')->fetchColumn());
        self::assertSame(1, (int) $this->connection->query('SELECT count(*) FROM automation_tokens')->fetchColumn());
    }

    /**
     * CI allocations and replay remain available during repository-provider outages.
     *
     * @return void
     */
    public function testUsesLocalAuthorizationDuringProviderOutage(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $token = $this->application->automation->createToken($session, $project, 'CI');
        $calls = $this->providerCalls;
        $this->isProviderUnavailable = true;
        self::assertSame(1, $this->allocate($project, $token['token'], 1));
        self::assertSame(1, $this->allocate($project, $token['token'], 1));
        self::assertSame($calls, $this->providerCalls);
    }

    /**
     * Validates expiry and revocation before allocation/replay, and rejects project-crossing access.
     *
     * @return void
     */
    public function testRejectsExpiredRevokedAndWrongProjectTokens(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $other = (new \Ordinal\Repository\ProjectRepository($this->connection))->createProject(new \Ordinal\Model\ProviderRepository(1, '78', 'Other'), 'Other');
        $token = $this->application->automation->createToken($session, $project, 'CI');
        self::assertSame(403, $this->request($other, $token['token'], 1)->statusCode);
        self::assertSame(1, $this->allocate($project, $token['token'], 1));
        $this->connection->exec("UPDATE automation_tokens SET created_at = CURRENT_TIMESTAMP - interval '91 days', expires_at = CURRENT_TIMESTAMP - interval '1 day'");
        self::assertSame(401, $this->request($project, $token['token'], 1)->statusCode);
        $replacement = $this->application->automation->rotateToken($session, $token['id']);
        self::assertSame(1, $this->allocate($project, $replacement['token'], 1));
        $this->application->automation->revokeToken($session, $token['id']);
        self::assertSame(401, $this->request($project, $replacement['token'], 1)->statusCode);
        self::assertSame(401, $this->request($project, $replacement['token'], 2)->statusCode);
        self::assertSame(2, (int) $this->connection->query('SELECT next_build_number FROM projects WHERE id = ' . $project)->fetchColumn());
    }

    /**
     * Only instance administrators enable no-expiration policy and old credentials retain their expiration.
     *
     * @return void
     */
    public function testAssignsPolicyOnCreationAndRotationOnly(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $token = $this->application->automation->createToken($session, $project, 'CI');
        try {
            $this->application->automation->createToken($session, $project, 'Unlimited CI', true);
            self::fail('No-expiration policy was bypassed.');
        } catch (AccountException $exception) {
            self::assertSame(403, $exception->statusCode);
        }
        $this->application->automation->savePolicy($session, 14, true);
        self::assertSame($token['expiresAt'], $this->connection->query('SELECT expires_at FROM automation_tokens')->fetchColumn());
        $unlimited = $this->application->automation->createToken($session, $project, 'Unlimited CI', true);
        self::assertNull($unlimited['expiresAt']);
        $this->application->automation->savePolicy($session, 3, false);
        self::assertSame(1, $this->allocate($project, $unlimited['token'], 1));
        $rotated = $this->application->automation->rotateToken($session, $unlimited['id']);
        self::assertNotNull($rotated['expiresAt']);
    }

    /**
     * Current repository administration is required for token management, and only instance admins change policy.
     *
     * @return void
     */
    public function testProtectsProjectTokenManagement(): void
    {
        $admin = $this->signInAdministrator();
        $project = $this->application->projects->createProject($admin, 1, '77', 'Project');
        $other = $this->application->sessions->authenticate($this->signIn('9'));
        $this->role = 30;
        try {
            $this->application->automation->createToken($other, $project, 'CI');
            self::fail('Write-only access created an administrator token.');
        } catch (AccountException $exception) {
            self::assertSame(403, $exception->statusCode);
        }
        $this->role = 40;
        $token = $this->application->automation->createToken($other, $project, 'CI');
        self::assertSame(1, $this->allocate($project, $token['token'], 1));
        $this->expectException(AccountException::class);
        $this->application->automation->savePolicy($other, 1, true);
    }

    /**
     * Rechecks rotation after initial authorization before a counter can change.
     *
     * @return void
     */
    public function testRejectsPreviouslyVerifiedSecretAfterRotation(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $token = $this->application->automation->createToken($session, $project, 'CI');
        $caller = $this->application->allocationAuthorizer->authorizeAllocation($project, $token['token']);
        $this->application->automation->rotateToken($session, $token['id']);
        $this->expectException(AuthenticationException::class);
        $this->application->buildNumbers->allocateBuildNumber($project, $this->createRequestId(1), $caller);
    }

    /**
     * The allocation core cannot bypass local token verification by omitting its verified hash.
     *
     * @return void
     */
    public function testRejectsAutomationCallerWithoutVerifiedHash(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $token = $this->application->automation->createToken($session, $project, 'CI');
        $this->expectException(AuthenticationException::class);
        $this->application->buildNumbers->allocateBuildNumber($project, $this->createRequestId(1), new \Ordinal\Model\AllocationCaller(automationTokenId: $token['id']));
    }

    /**
     * A Bearer credential cannot replace protected browser authentication or CSRF.
     *
     * @return void
     */
    public function testRestrictsTokensToAllocation(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $token = $this->application->automation->createToken($session, $project, 'CI');
        $router = new \Ordinal\Http\Router($this->application);
        foreach (['/account', '/administration', '/devices', '/automation?projectId=' . $project] as $path) {
            self::assertSame(401, $router->dispatch('GET', $path, authorizationHeader: 'Bearer ' . $token['token'], isSecure: true)->statusCode);
        }
        self::assertSame(405, $router->dispatch('GET', '/api/projects/' . $project . '/build-numbers', authorizationHeader: 'Bearer ' . $token['token'], isSecure: true)->statusCode);
    }

    /**
     * Protects browser creation, one-time display, rename, rotation, revocation, and instance policy with CSRF.
     *
     * @return void
     */
    public function testManagesTokensThroughProtectedBrowserForms(): void
    {
        $secret = $this->signIn();
        $session = $this->application->sessions->authenticate($secret);
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $router = new \Ordinal\Http\Router($this->application);
        $cookies = [\Ordinal\Service\BrowserSessionService::COOKIE_NAME => $secret];
        $fields = ['projectId' => (string) $project, 'action' => 'create', 'name' => '<CI>', 'hasNoExpiration' => '0'];
        self::assertSame(403, $router->dispatch('POST', '/automation', http_build_query($fields), null, 'application/x-www-form-urlencoded', $cookies, true)->statusCode);
        $fields['csrfToken'] = $session->csrfToken;
        $issued = $router->dispatch('POST', '/automation', http_build_query($fields), null, 'application/x-www-form-urlencoded', $cookies, true);
        self::assertSame(200, $issued->statusCode, $issued->body);
        self::assertSame('no-store', $issued->headers['Cache-Control']);
        self::assertSame('no-referrer', $issued->headers['Referrer-Policy']);
        self::assertStringContainsString('&lt;CI&gt;', $issued->body);
        self::assertMatchesRegularExpression('/automation\.1\.[a-f0-9]{64}/', $issued->body);
        $page = $router->dispatch('GET', '/automation?projectId=' . $project, cookies: $cookies, isSecure: true);
        self::assertSame(200, $page->statusCode);
        self::assertDoesNotMatchRegularExpression('/automation\.1\.[a-f0-9]{64}/', $page->body);
        $fields['tokenId'] = '1';
        $fields['action'] = 'rename';
        $fields['name'] = 'Release';
        self::assertSame(303, $router->dispatch('POST', '/automation', http_build_query($fields), null, 'application/x-www-form-urlencoded', $cookies, true)->statusCode);
        self::assertSame('Release', $this->connection->query('SELECT name FROM automation_tokens')->fetchColumn());
        $fields['action'] = 'rotate';
        self::assertSame(200, $router->dispatch('POST', '/automation', http_build_query($fields), null, 'application/x-www-form-urlencoded', $cookies, true)->statusCode);
        $fields['action'] = 'revoke';
        self::assertSame(303, $router->dispatch('POST', '/automation', http_build_query($fields), null, 'application/x-www-form-urlencoded', $cookies, true)->statusCode);
        self::assertNotNull($this->connection->query('SELECT revoked_at FROM automation_tokens')->fetchColumn());
        $policy = ['csrfToken' => $session->csrfToken, 'lifetimeDays' => '14', 'isWithoutExpirationAllowed' => '1'];
        self::assertSame(303, $router->dispatch('POST', '/automation/policy', http_build_query($policy), null, 'application/x-www-form-urlencoded', $cookies, true)->statusCode);
        self::assertSame(14, (int) $this->connection->query('SELECT automation_lifetime_days FROM instance_settings')->fetchColumn());
    }

    /**
     * Committed rotation rejects old hashes in both independent callers waiting at the token lock.
     *
     * @return void
     */
    public function testSerializesRotationBeforeWaitingAllocations(): void
    {
        $this->assertWaitingCallersAreRejected(false);
    }

    /**
     * Committed revocation rejects both independent callers waiting at the token lock.
     *
     * @return void
     */
    public function testSerializesRevocationBeforeWaitingAllocations(): void
    {
        $this->assertWaitingCallersAreRejected(true);
    }

    /**
     * Rechecks wall-clock expiration after waiting for an unchanged token row lock.
     *
     * @return void
     */
    public function testRejectsTokenThatExpiresWhileWaitingForItsLock(): void
    {
        $this->assertWaitingCallersAreRejected(false, true);
    }

    /**
     * Observes independent PostgreSQL callers before releasing an invalidated credential.
     *
     * @param bool $isRevoked
     * @param bool $isExpired
     * @return void
     */
    private function assertWaitingCallersAreRejected(bool $isRevoked, bool $isExpired = false): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $token = $this->application->automation->createToken($session, $project, 'CI');
        if ($isExpired) {
            $this->connection->exec("UPDATE automation_tokens SET expires_at = clock_timestamp() + interval '2 seconds'");
        }
        $parsed = \Ordinal\Security\AutomationToken::parseToken($token['token']);
        $hash = hash('sha256', $parsed['secret']);
        $newSecret = \Ordinal\Security\AutomationToken::createSecret();
        $scripts = [];
        for ($index = 1; $index <= 2; $index++) {
            $scripts[] = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; echo \\Ordinal\\Tests\\Support\\AutomationAllocationWorker::allocate(' . var_export($this->schemaName, true) . ', ' . $token['id'] . ', ' . var_export($hash, true) . ', ' . var_export($this->createRequestId($index), true) . ');';
        }
        $this->connection->beginTransaction();
        (new \Ordinal\Repository\AutomationRepository($this->connection))->findToken($token['id'], true);
        $outputs = \Ordinal\Tests\Support\ConcurrentAllocations::run($this->connection, $this->schemaName, $scripts,
            /**
             * Completes rotation, revocation or expiration before either blocked allocation proceeds.
             *
             * @return void
             */
            function () use ($token, $newSecret, $isRevoked, $isExpired): void {
                $repository = new \Ordinal\Repository\AutomationRepository($this->connection);
                if ($isExpired) {
                    $this->connection->query('SELECT pg_sleep(GREATEST(0, EXTRACT(EPOCH FROM (expires_at - clock_timestamp()))::double precision) + 0.1) FROM automation_tokens');
                } elseif ($isRevoked) {
                    $repository->revokeToken($token['id']);
                } else {
                    $repository->rotateToken($token['id'], hash('sha256', $newSecret), 90);
                }
            },
        );
        self::assertSame(['INVALID_AUTHENTICATION', 'INVALID_AUTHENTICATION'], $outputs);
        self::assertSame(0, (int) $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        self::assertSame(1, (int) $this->connection->query('SELECT next_build_number FROM projects')->fetchColumn());
        if (!$isRevoked && !$isExpired) {
            self::assertSame(1, $this->allocate($project, \Ordinal\Security\AutomationToken::createToken($token['id'], $newSecret), 1));
        }
    }

    /**
     * Failed audit persistence rolls back both token issuance and secret rotation.
     *
     * @return void
     */
    public function testRollsBackTokenChangesWhenAuditFails(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $token = $this->application->automation->createToken($session, $project, 'CI');
        $hash = $this->connection->query('SELECT secret_hash FROM automation_tokens')->fetchColumn();
        $this->connection->exec("ALTER TABLE audit_events ADD CONSTRAINT reject_automation_changes CHECK (action NOT IN ('automation_token_created', 'automation_token_rotated')) NOT VALID");
        foreach (['create', 'rotate'] as $action) {
            try {
                match ($action) {
                    'create' => $this->application->automation->createToken($session, $project, 'Other CI'),
                    'rotate' => $this->application->automation->rotateToken($session, $token['id']),
                };
                self::fail('Failed audit committed a credential change.');
            } catch (\PDOException) {
                self::assertSame($hash, $this->connection->query('SELECT secret_hash FROM automation_tokens')->fetchColumn());
                self::assertSame(1, (int) $this->connection->query('SELECT count(*) FROM automation_tokens')->fetchColumn());
            }
        }
        self::assertSame(1, $this->allocate($project, $token['token'], 1));
    }

    /**
     * Calls the routed API with trusted HTTPS context.
     *
     * @param int $projectId
     * @param string $token
     * @param int $request
     * @return \Ordinal\Http\Response
     */
    private function request(int $projectId, string $token, int $request): \Ordinal\Http\Response
    {
        return (new \Ordinal\Http\Router($this->application))->dispatch('POST', '/api/projects/' . $projectId . '/build-numbers', json_encode(['requestId' => $this->createRequestId($request)], JSON_THROW_ON_ERROR), 'Bearer ' . $token, 'application/json', [], true);
    }

    /**
     * Extracts a successful allocation response.
     *
     * @param int $projectId
     * @param string $token
     * @param int $request
     * @return int
     */
    private function allocate(int $projectId, string $token, int $request): int
    {
        $response = $this->request($projectId, $token, $request);
        self::assertSame(200, $response->statusCode, $response->body);
        return json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['buildNumber'];
    }

    /**
     * Produces a deterministic canonical UUID v4 for one build attempt.
     *
     * @param int $value
     * @return string
     */
    private function createRequestId(int $value): string
    {
        return '11111111-1111-4111-8111-' . str_pad((string) $value, 12, '0', STR_PAD_LEFT);
    }
}
