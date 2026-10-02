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

/** Exercises device approval, protected forms, lifetime policies, authorized replay, and concurrency on PostgreSQL. */
final class DeviceServiceTest extends TestCase
{
    /** Bounds concurrency lock observation to keep failing tests finite. */
    private const int LOCK_WAIT_SECONDS = 8;
    /** Avoids busy polling while observing worker sessions. */
    private const int LOCK_POLL_MICROSECONDS = 20_000;
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
        $this->schemaName = 'device_test_' . bin2hex(random_bytes(8));
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
     * Keeps stable device IDs, project-specific secrets, and hashed persistence.
     *
     * @return void
     */
    public function testEnrollsAndReusesOwnedDevice(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $first = $this->application->devices->enrollDevice($session, $project, 'My laptop');
        $second = $this->application->devices->enrollDevice($session, $project, '', $first['deviceId']);
        self::assertSame($first['deviceId'], $second['deviceId']);
        self::assertNotSame($first['credentialId'], $second['credentialId']);
        self::assertNotSame($first['token'], $second['token']);
        $row = $this->connection->query('SELECT * FROM device_credentials ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $parsed = \Ordinal\Security\DeviceToken::parseToken($first['token']);
        self::assertSame(hash('sha256', $parsed['secret']), $row['secret_hash']);
        self::assertSame(30, $row['lifetime_days']);
        self::assertSame(30, (int) $this->connection->query("SELECT extract(day FROM expires_at - authenticated_at) FROM device_credentials LIMIT 1")->fetchColumn());
        self::assertSame('My laptop', $this->connection->query('SELECT name FROM devices')->fetchColumn());
        $event = json_decode($this->connection->query("SELECT details FROM audit_events WHERE action = 'device_credential_created' ORDER BY id LIMIT 1")->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['deviceId' => $first['deviceId'], 'credentialId' => $first['credentialId']], $event);
        self::assertSame(1, $this->allocate($project, $first['token'], 1));
        self::assertStringNotContainsString($parsed['secret'], json_encode($this->application->devices->getDeviceData($session), JSON_THROW_ON_ERROR));
    }

    /**
     * Requires repository write even for instance administrators approving enrollment.
     *
     * @return void
     */
    public function testRejectsEnrollmentWithoutWritePermission(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $this->role = 20;
        $this->expectException(AccountException::class);
        $this->application->devices->enrollDevice($session, $project, 'Laptop');
    }

    /**
     * Preserves old expiry while new credentials use defaults and per-project overrides.
     *
     * @return void
     */
    public function testAppliesPolicyOnlyToNewCredentials(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $first = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        $oldExpiry = $first['expiresAt'];
        $this->application->devices->saveAuthenticationPolicy($session, null, true, 7);
        $second = $this->application->devices->enrollDevice($session, $project, '', $first['deviceId']);
        self::assertSame(7, $second['lifetimeDays']);
        self::assertSame($oldExpiry, $this->connection->query('SELECT expires_at FROM device_credentials WHERE id = ' . $first['credentialId'])->fetchColumn());
        $this->application->devices->saveAuthenticationPolicy($session, $project, null, -1);
        $third = $this->application->devices->enrollDevice($session, $project, '', $first['deviceId']);
        self::assertSame(-1, $third['lifetimeDays']);
        self::assertNull($third['expiresAt']);
        $this->application->devices->saveAuthenticationPolicy($session, $project, null, null);
        self::assertSame(7, $this->application->devices->getProjectPolicy($session, $project)['device_lifetime_days']);
    }

    /**
     * Keeps the expiration absolute and requires the same user to authenticate again for replay.
     *
     * @return void
     */
    public function testReplaysWithReplacementCredentialAfterExpiration(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $first = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        $expiry = $first['expiresAt'];
        self::assertSame(1, $this->allocate($project, $first['token'], 1));
        self::assertSame($expiry, $this->connection->query('SELECT expires_at FROM device_credentials')->fetchColumn());
        $this->connection->exec("UPDATE device_credentials SET authenticated_at = CURRENT_TIMESTAMP - interval '31 days', provider_sign_in_at = CURRENT_TIMESTAMP - interval '31 days', expires_at = CURRENT_TIMESTAMP - interval '1 day'");
        self::assertSame(401, $this->request($project, $first['token'], 1)->statusCode);
        $session = $this->signInAdministrator();
        $replacement = $this->application->devices->enrollDevice($session, $project, '', $first['deviceId']);
        self::assertSame(1, $this->allocate($project, $replacement['token'], 1));
        self::assertSame(2, (int) $this->connection->query('SELECT next_build_number FROM projects')->fetchColumn());
        $other = $this->application->sessions->authenticate($this->signIn('9'));
        $credential = $this->application->devices->enrollDevice($other, $project, 'Other laptop');
        self::assertSame(2, $this->allocate($project, $credential['token'], 1));
    }

    /**
     * Consumes one authorization, allows its replay, and requires another actual provider sign-in.
     *
     * @return void
     */
    public function testConsumesSingleAllocationAndRequiresFreshSignIn(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $this->application->devices->saveAuthenticationPolicy($session, $project, null, 0);
        $credential = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        self::assertSame(1, $this->allocate($project, $credential['token'], 1));
        self::assertSame(1, $this->allocate($project, $credential['token'], 1));
        self::assertSame(401, $this->request($project, $credential['token'], 2)->statusCode);
        try {
            $this->application->devices->enrollDevice($session, $project, '', $credential['deviceId']);
            self::fail('An old sign-in approved another single-use credential.');
        } catch (AccountException $exception) {
            self::assertSame(401, $exception->statusCode);
        }
        $fresh = $this->signInAdministrator();
        $next = $this->application->devices->enrollDevice($fresh, $project, '', $credential['deviceId']);
        self::assertSame(2, $this->allocate($project, $next['token'], 2));
    }

    /**
     * Rechecks provider permission and outages even for saved lifetime-zero retries.
     *
     * @return void
     */
    public function testChecksProviderOnEveryReplay(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $credential = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        self::assertSame(1, $this->allocate($project, $credential['token'], 1));
        $this->role = 20;
        self::assertSame(403, $this->request($project, $credential['token'], 1)->statusCode);
        $this->role = 30;
        $this->isProviderUnavailable = true;
        self::assertSame(503, $this->request($project, $credential['token'], 2)->statusCode);
        self::assertSame(503, $this->request($project, $credential['token'], 1)->statusCode);
        self::assertSame(2, (int) $this->connection->query('SELECT next_build_number FROM projects')->fetchColumn());
    }

    /**
     * Checks credential scope and revocation before contacting an unavailable provider.
     *
     * @return void
     */
    public function testRevokesIndividualAndEntireDevice(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $otherProject = (new \Ordinal\Repository\ProjectRepository($this->connection))->createProject(new \Ordinal\Model\ProviderRepository(1, '78', 'Other'), 'Other');
        $first = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        $second = $this->application->devices->enrollDevice($session, $otherProject, '', $first['deviceId']);
        self::assertSame(403, $this->request($otherProject, $first['token'], 1)->statusCode);
        $this->application->devices->revokeCredential($session, $first['credentialId']);
        $this->isProviderUnavailable = true;
        self::assertSame(401, $this->request($project, $first['token'], 1)->statusCode);
        $this->application->devices->revokeDevice($session, $first['deviceId']);
        self::assertSame(401, $this->request($otherProject, $second['token'], 2)->statusCode);
        self::assertSame(2, (int) $this->connection->query('SELECT count(*) FROM device_credentials WHERE revoked_at IS NOT NULL')->fetchColumn());
    }

    /**
     * Denies anonymous replays after authentication is enabled again.
     *
     * @return void
     */
    public function testAllocatesAnonymouslyWithoutProviderWhenPolicyAllows(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $this->application->devices->saveAuthenticationPolicy($session, $project, false, null);
        $this->isProviderUnavailable = true;
        self::assertSame(1, $this->allocate($project, null, 1));
        self::assertSame(1, $this->allocate($project, null, 1));
        $this->application->devices->saveAuthenticationPolicy($session, $project, true, null);
        self::assertSame(401, $this->request($project, null, 1)->statusCode);
    }

    /**
     * Rechecks revocation after initial authorization and rolls back failed successful auditing.
     *
     * @return void
     */
    public function testRechecksRevocationInAllocationTransaction(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $credential = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        $caller = $this->application->allocationAuthorizer->authorizeAllocation($project, $credential['token']);
        $this->application->devices->revokeDevice($session, $credential['deviceId']);
        $this->expectException(\Ordinal\Security\AuthenticationException::class);
        $this->application->buildNumbers->allocateBuildNumber($project, $this->createRequestId(1), $caller);
    }

    /**
     * Cannot reuse another account's device or change authentication policy as a contributor.
     *
     * @return void
     */
    public function testProtectsOwnershipAndPolicy(): void
    {
        $admin = $this->signInAdministrator();
        $project = $this->application->projects->createProject($admin, 1, '77', 'Project');
        $credential = $this->application->devices->enrollDevice($admin, $project, 'Laptop');
        $other = $this->application->sessions->authenticate($this->signIn('9'));
        foreach (['enroll', 'policy', 'device'] as $action) {
            try {
                match ($action) {
                    'enroll' => $this->application->devices->enrollDevice($other, $project, '', $credential['deviceId']),
                    'policy' => $this->application->devices->saveAuthenticationPolicy($other, $project, false, -1),
                    'device' => $this->application->devices->revokeDevice($other, $credential['deviceId']),
                };
                self::fail('Protected operation was allowed.');
            } catch (AccountException $exception) {
                self::assertSame(403, $exception->statusCode);
            }
        }
    }

    /**
     * Returns a single-use credential unchanged when auditing fails.
     *
     * @return void
     */
    public function testRollsBackConsumptionWithFailedAudit(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $this->application->devices->saveAuthenticationPolicy($session, $project, null, 0);
        $credential = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        $this->connection->exec("ALTER TABLE audit_events ADD CONSTRAINT reject_allocation CHECK (action <> 'allocate_build_number')");
        try {
            $this->allocate($project, $credential['token'], 1);
            self::fail('A failed audit committed allocation.');
        } catch (\PDOException) {
            self::assertNull($this->connection->query('SELECT consumed_allocation_id FROM device_credentials')->fetchColumn());
            self::assertSame(0, (int) $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
            self::assertSame(1, (int) $this->connection->query('SELECT next_build_number FROM projects')->fetchColumn());
        }
    }





    /**
     * Serializes independent lifetime-zero workers so exactly one distinct request succeeds.
     *
     * @return void
     */
    public function testSerializesConcurrentSingleAllocation(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $this->application->devices->saveAuthenticationPolicy($session, $project, null, 0);
        $credential = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        $outputs = $this->runConcurrentAllocations($credential['credentialId'], $session->userId, false);
        sort($outputs);
        self::assertSame(['1', 'INVALID_AUTHENTICATION'], $outputs);
        self::assertSame(1, (int) $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        self::assertSame(2, (int) $this->connection->query('SELECT next_build_number FROM projects')->fetchColumn());
    }

    /**
     * Concurrent retries share the original allocation and consumption record.
     *
     * @return void
     */
    public function testSerializesConcurrentSingleAllocationReplay(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $this->application->devices->saveAuthenticationPolicy($session, $project, null, 0);
        $credential = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        self::assertSame(['1', '1'], $this->runConcurrentAllocations($credential['credentialId'], $session->userId, true));
        self::assertSame(1, (int) $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
    }

    /**
     * A committed device-wide revocation rejects both callers already waiting at the device lock.
     *
     * @return void
     */
    public function testSerializesRevocationBeforeWaitingAllocations(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $credential = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        self::assertSame(['INVALID_AUTHENTICATION', 'INVALID_AUTHENTICATION'], $this->runConcurrentAllocations($credential['credentialId'], $session->userId, false, true));
        self::assertSame(0, (int) $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        self::assertSame(1, (int) $this->connection->query('SELECT next_build_number FROM projects')->fetchColumn());
    }

    /**
     * One stable device follows independent project policies, including no time expiration.
     *
     * @return void
     */
    public function testKeepsLifetimesIndependentForOneDevice(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $other = (new \Ordinal\Repository\ProjectRepository($this->connection))->createProject(new \Ordinal\Model\ProviderRepository(1, '78', 'Other'), 'Other');
        $this->application->devices->saveAuthenticationPolicy($session, $project, null, 0);
        $this->application->devices->saveAuthenticationPolicy($session, $other, null, -1);
        $single = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        $unlimited = $this->application->devices->enrollDevice($session, $other, '', $single['deviceId']);
        $this->connection->exec("UPDATE device_credentials SET authenticated_at = CURRENT_TIMESTAMP - interval '100 years', provider_sign_in_at = CURRENT_TIMESTAMP - interval '100 years' WHERE lifetime_days = -1");
        self::assertSame(1, $this->allocate($project, $single['token'], 1));
        self::assertSame(401, $this->request($project, $single['token'], 2)->statusCode);
        self::assertSame(1, $this->allocate($other, $unlimited['token'], 1));
        self::assertSame(2, $this->allocate($other, $unlimited['token'], 2));
    }

    /**
     * Rejects wrong secrets, archive state, and stale single-use sign-in without persisting credentials.
     *
     * @return void
     */
    public function testRejectsInvalidLocalStateBeforeProviderVerification(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $credential = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        $calls = $this->providerCalls;
        $this->isProviderUnavailable = true;
        self::assertSame(401, $this->request($project, 'device.' . $credential['credentialId'] . '.' . str_repeat('0', 64), 1)->statusCode);
        $this->connection->exec('UPDATE projects SET archived_at = CURRENT_TIMESTAMP');
        self::assertSame(404, $this->request($project, $credential['token'], 1)->statusCode);
        self::assertSame($calls, $this->providerCalls);
        $this->connection->exec('UPDATE projects SET archived_at = NULL');
        $this->isProviderUnavailable = false;
        $this->application->devices->saveAuthenticationPolicy($session, $project, null, 0);
        $this->connection->exec("UPDATE browser_sessions SET provider_reauthenticated_at = CURRENT_TIMESTAMP - interval '6 minutes'");
        try {
            $this->application->devices->enrollDevice($session, $project, 'Another laptop');
            self::fail('A stale browser sign-in approved single-use authentication.');
        } catch (AccountException $exception) {
            self::assertSame(401, $exception->statusCode);
            self::assertSame(1, (int) $this->connection->query('SELECT count(*) FROM devices')->fetchColumn());
        }
    }

    /**
     * Project administrators can revoke individual credentials while write-only contributors cannot.
     *
     * @return void
     */
    public function testProtectsProjectAdministratorRevocation(): void
    {
        $admin = $this->signInAdministrator();
        $project = $this->application->projects->createProject($admin, 1, '77', 'Project');
        $credential = $this->application->devices->enrollDevice($admin, $project, 'Laptop');
        $other = $this->application->sessions->authenticate($this->signIn('9'));
        $this->role = 30;
        self::assertSame([], $this->application->devices->getDeviceData($other, $project)['credentials']);
        try {
            $this->application->devices->revokeCredential($other, $credential['credentialId']);
            self::fail('Write permission granted credential administration.');
        } catch (AccountException $exception) {
            self::assertSame(403, $exception->statusCode);
            self::assertNull($this->connection->query('SELECT revoked_at FROM device_credentials')->fetchColumn());
        }
        $this->role = 40;
        self::assertSame($credential['credentialId'], (int) $this->application->devices->getDeviceData($other, $project)['credentials'][0]['id']);
        $this->application->devices->revokeCredential($other, $credential['credentialId']);
        self::assertSame(401, $this->request($project, $credential['token'], 1)->statusCode);
        $owned = $this->application->devices->enrollDevice($other, $project, 'Other laptop');
        $this->isProviderUnavailable = true;
        $this->application->devices->revokeDevice($admin, $owned['deviceId']);
        self::assertSame(401, $this->request($project, $owned['token'], 1)->statusCode);
    }

    /**
     * Insecure allocation requests cannot use credentials or advance counters.
     *
     * @return void
     */
    public function testRequiresHttpsForAllocation(): void
    {
        $session = $this->signInAdministrator();
        $project = $this->application->projects->createProject($session, 1, '77', 'Project');
        $credential = $this->application->devices->enrollDevice($session, $project, 'Laptop');
        $response = (new \Ordinal\Http\Router($this->application))->dispatch('POST', '/api/projects/' . $project . '/build-numbers', json_encode(['requestId' => $this->createRequestId(1)], JSON_THROW_ON_ERROR), 'Bearer ' . $credential['token'], 'application/json');
        self::assertSame(401, $response->statusCode);
        self::assertSame(1, (int) $this->connection->query('SELECT next_build_number FROM projects')->fetchColumn());
    }

    /**
     * Runs two independent PHP processes while holding the common device mutex.
     *
     * @param int $credentialId
     * @param int $userId
     * @param bool $isSameRequest
     * @param bool $isRevoked
     * @return array
     */
    private function runConcurrentAllocations(int $credentialId, int $userId, bool $isSameRequest, bool $isRevoked = false): array
    {
        $workers = [];
        $outputs = [];
        $this->connection->beginTransaction();
        $this->connection->query('SELECT id FROM devices WHERE id = 1 FOR UPDATE')->fetchColumn();
        try {
            for ($index = 1; $index <= 2; $index++) {
                $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; echo \\Ordinal\\Tests\\Support\\DeviceAllocationWorker::allocate(' . var_export($this->schemaName, true) . ', ' . $credentialId . ', ' . $userId . ', ' . var_export($this->createRequestId($isSameRequest ? 1 : $index), true) . ');';
                $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
                self::assertIsResource($process);
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'output' => $pipes[1], 'error' => $pipes[2]];
            }
            $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
            $statement = $this->connection->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock'");
            do {
                $this->connection->query('SELECT pg_stat_clear_snapshot()');
                $statement->execute(['name' => $this->schemaName]);
                $waiting = (int) $statement->fetchColumn();
                if ($waiting === 2) {
                    break;
                }
                usleep(self::LOCK_POLL_MICROSECONDS);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both workers must contend for the locked device.');
            if ($isRevoked) {
                (new \Ordinal\Repository\DeviceRepository($this->connection))->revokeDevice(1);
            }
            $this->connection->commit();
            foreach ($workers as &$worker) {
                $output = '';
                $error = '';
                $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
                do {
                    $output .= stream_get_contents($worker['output']);
                    $error .= stream_get_contents($worker['error']);
                    $status = proc_get_status($worker['process']);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(self::LOCK_POLL_MICROSECONDS);
                } while (microtime(true) < $deadline);
                self::assertFalse($status['running'], 'Allocation worker exceeded its deadline.');
                $output .= stream_get_contents($worker['output']);
                $error .= stream_get_contents($worker['error']);
                fclose($worker['output']);
                fclose($worker['error']);
                self::assertSame(0, proc_close($worker['process']), $error);
                $worker['process'] = null;
                $outputs[] = $output;
            }
            unset($worker);
        } finally {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                    foreach (['output', 'error'] as $pipe) {
                        if (is_resource($worker[$pipe])) {
                            fclose($worker[$pipe]);
                        }
                    }
                    proc_close($worker['process']);
                }
            }
        }
        return $outputs;
    }

    /**
     * Calls the real routed API with trusted HTTPS context.
     *
     * @param int $projectId
     * @param ?string $token
     * @param int $request
     * @return \Ordinal\Http\Response
     */
    private function request(int $projectId, ?string $token, int $request): \Ordinal\Http\Response
    {
        return (new \Ordinal\Http\Router($this->application))->dispatch('POST', '/api/projects/' . $projectId . '/build-numbers', json_encode(['requestId' => $this->createRequestId($request)], JSON_THROW_ON_ERROR), $token === null ? null : 'Bearer ' . $token, 'application/json', [], true);
    }

    /**
     * Extracts a successful allocation response.
     *
     * @param int $projectId
     * @param ?string $token
     * @param int $request
     * @return int
     */
    private function allocate(int $projectId, ?string $token, int $request): int
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
