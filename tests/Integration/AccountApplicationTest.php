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

/** Exercises OAuth, encrypted persistence, sessions, bootstrap administration, and repository roles on PostgreSQL. */
final class AccountApplicationTest extends TestCase
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

    /**
     * Creates an isolated schema, external-style configuration, and deterministic provider responses.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection = TestDatabase::createConnection();
        $this->schemaName = 'account_test_' . bin2hex(random_bytes(8));
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
     * Does not grant the first visitor administrator privileges and never stores plaintext tokens.
     *
     * @return void
     */
    public function testCreatesIdentityAndEncryptedAuthorizationWithoutFirstVisitorAdmin(): void
    {
        $session = $this->application->sessions->authenticate($this->signIn('9'));
        self::assertFalse($this->application->administration->isInstanceAdministrator($session->userId));
        self::assertSame('9', $session->providerUserId);
        $row = $this->connection->query("SELECT encode(encrypted_access_token, 'hex') AS access, encode(encrypted_refresh_token, 'hex') AS refresh FROM provider_authorizations")->fetch(PDO::FETCH_ASSOC);
        self::assertNotSame(bin2hex('access-9'), $row['access']);
        self::assertNotSame(bin2hex('refresh-9'), $row['refresh']);
        self::assertSame('access-9', $this->application->authorizations->getAuthorization($session->userId)->accessToken);
        self::assertSame(0, (int) $this->connection->query('SELECT count(*) FROM projects')->fetchColumn());
    }

    /**
     * Requires the browser-bound single-use state before any identity can be saved.
     *
     * @return void
     */
    public function testRejectsWrongBrowserAndStateReplay(): void
    {
        $request = $this->application->login->beginLogin(1);
        parse_str((string) parse_url($request['authorizationUrl'], PHP_URL_QUERY), $query);
        try {
            $this->application->login->completeLogin($query['state'], '8', str_repeat('f', 64));
            self::fail('Wrong browser was accepted.');
        } catch (AuthenticationException) {
            self::assertSame(0, (int) $this->connection->query('SELECT count(*) FROM users')->fetchColumn());
        }
        $this->application->login->completeLogin($query['state'], '8', $request['browserSecret']);
        $this->expectException(AuthenticationException::class);
        $this->application->login->completeLogin($query['state'], '8', $request['browserSecret']);
    }

    /**
     * Rejects expired state without creating identities or sessions.
     *
     * @return void
     */
    public function testRejectsExpiredLoginAttempt(): void
    {
        $request = $this->application->login->beginLogin(1);
        parse_str((string) parse_url($request['authorizationUrl'], PHP_URL_QUERY), $query);
        $this->connection->exec("UPDATE oauth_attempts SET expires_at = clock_timestamp() - interval '1 second'");
        $this->expectException(AuthenticationException::class);
        $this->application->login->completeLogin($query['state'], '8', $request['browserSecret']);
    }

    /**
     * Keeps identical immutable IDs and names from different provider servers separate.
     *
     * @return void
     */
    public function testSeparatesProviderInstances(): void
    {
        $admin = $this->signInAdministrator();
        $otherConnectionId = $this->application->administration->saveConnection($admin, 'secondary', 'Other GitLab', true);
        $other = $this->application->sessions->authenticate($this->signIn('8', $otherConnectionId));
        self::assertNotSame($admin->userId, $other->userId);
        self::assertFalse($this->application->administration->isInstanceAdministrator($other->userId));
    }

    /**
     * Serializes token refresh, saves rotation, and reuses the replacement on subsequent calls.
     *
     * @return void
     */
    public function testRefreshesExpiredAuthorizationOnce(): void
    {
        $admin = $this->signInAdministrator();
        $this->connection->exec("UPDATE provider_authorizations SET access_expires_at = clock_timestamp() - interval '1 second'");
        self::assertSame('access-8', $this->application->authorizations->getAuthorization($admin->userId)->accessToken);
        $this->application->authorizations->getAuthorization($admin->userId);
        self::assertSame(1, $this->refreshCalls);
        self::assertSame(1, (int) $this->connection->query('SELECT refresh_version FROM provider_authorizations')->fetchColumn());
    }

    /**
     * Preserves stored credentials when provider refresh cannot complete.
     *
     * @return void
     */
    public function testRollsBackUnavailableRefresh(): void
    {
        $admin = $this->signInAdministrator();
        $this->connection->exec("UPDATE provider_authorizations SET access_expires_at = clock_timestamp() - interval '1 second'");
        $before = $this->connection->query("SELECT encode(encrypted_access_token, 'hex') FROM provider_authorizations")->fetchColumn();
        $this->isProviderUnavailable = true;
        try {
            $this->application->authorizations->getAuthorization($admin->userId);
            self::fail('Provider outage was ignored.');
        } catch (\Ordinal\Provider\ProviderUnavailableException) {
            self::assertSame($before, $this->connection->query("SELECT encode(encrypted_access_token, 'hex') FROM provider_authorizations")->fetchColumn());
            self::assertSame(0, (int) $this->connection->query('SELECT refresh_version FROM provider_authorizations')->fetchColumn());
        }
    }

    /**
     * Enforces idle timeout without extending the absolute session limit.
     *
     * @return void
     */
    public function testExpiresIdleSession(): void
    {
        $secret = $this->signIn();
        $session = $this->application->sessions->authenticate($secret);
        self::assertSame(30, (int) $this->connection->query('SELECT idle_minutes FROM browser_sessions')->fetchColumn());
        self::assertSame(720, (int) $this->connection->query('SELECT absolute_minutes FROM browser_sessions')->fetchColumn());
        $this->connection->exec("UPDATE browser_sessions SET created_at = clock_timestamp() - interval '2 hours', last_activity_at = clock_timestamp() - interval '31 minutes'");
        $this->expectException(AuthenticationException::class);
        $this->application->sessions->authenticate($secret);
    }

    /**
     * Enforces the absolute timeout despite recent activity.
     *
     * @return void
     */
    public function testExpiresAbsoluteSession(): void
    {
        $secret = $this->signIn();
        $this->connection->exec("UPDATE browser_sessions SET created_at = clock_timestamp() - interval '13 hours', expires_at = clock_timestamp() - interval '1 second'");
        $this->expectException(AuthenticationException::class);
        $this->application->sessions->authenticate($secret);
    }

    /**
     * Revokes logout credentials while keeping stable session records.
     *
     * @return void
     */
    public function testRevokesSessionOnLogout(): void
    {
        $secret = $this->signIn();
        $session = $this->application->sessions->authenticate($secret);
        $this->application->sessions->revokeSession($session);
        self::assertSame(1, (int) $this->connection->query('SELECT count(*) FROM browser_sessions')->fetchColumn());
        $this->expectException(AuthenticationException::class);
        $this->application->sessions->authenticate($secret);
    }

    /**
     * Protects the configured bootstrap identity from website removal.
     *
     * @return void
     */
    public function testProtectsBootstrapAdministrator(): void
    {
        $admin = $this->signInAdministrator();
        self::assertTrue($this->application->administration->isInstanceAdministrator($admin->userId));
        $this->expectException(AccountException::class);
        $this->application->administration->setAdministrator($admin, $admin->userId, false);
    }

    /**
     * Requires recent provider reauthentication for administrator access changes.
     *
     * @return void
     */
    public function testRequiresRecentReauthenticationForAdministratorChanges(): void
    {
        $secret = $this->signIn();
        $other = $this->application->sessions->authenticate($this->signIn('9'));
        $this->connection->exec("UPDATE browser_sessions SET provider_reauthenticated_at = clock_timestamp() - interval '6 minutes'");
        $admin = $this->application->sessions->authenticate($secret);
        $this->expectException(AccountException::class);
        $this->application->administration->setAdministrator($admin, $other->userId, true);
    }

    /**
     * Grants and revokes additional administration without deleting the identity.
     *
     * @return void
     */
    public function testManagesAdditionalAdministrators(): void
    {
        $admin = $this->signInAdministrator();
        $other = $this->application->sessions->authenticate($this->signIn('9'));
        $this->application->administration->setAdministrator($admin, $other->userId, true);
        self::assertTrue($this->application->administration->isInstanceAdministrator($other->userId));
        $this->application->administration->setAdministrator($admin, $other->userId, false);
        self::assertFalse($this->application->administration->isInstanceAdministrator($other->userId));
        self::assertSame(2, (int) $this->connection->query('SELECT count(*) FROM users')->fetchColumn());
    }

    /**
     * Prevents non-administrators from configuring connections, settings, or projects.
     *
     * @return void
     */
    public function testRejectsOutsiderAdministration(): void
    {
        $user = $this->application->sessions->authenticate($this->signIn('9'));
        $this->expectException(AccountException::class);
        $this->application->administration->saveConnection($user, 'secondary', 'Other', true);
    }

    /**
     * Prevents disabling the bootstrap sign-in connection through the website.
     *
     * @return void
     */
    public function testProtectsBootstrapConnection(): void
    {
        $admin = $this->signInAdministrator();
        $this->expectException(AccountException::class);
        $this->application->administration->saveConnection($admin, 'primary', 'Primary', false);
    }

    /**
     * Changes future session limits only through instance administration.
     *
     * @return void
     */
    public function testChangesSessionLimitsForNewSessions(): void
    {
        $admin = $this->signInAdministrator();
        $this->application->administration->saveSessionLimits($admin, 10, 120);
        $this->signIn('9');
        self::assertSame([30, 10], array_map('intval', $this->connection->query('SELECT idle_minutes FROM browser_sessions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame([720, 120], array_map('intval', $this->connection->query('SELECT absolute_minutes FROM browser_sessions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Links immutable repositories once and derives roles from current provider membership.
     *
     * @return void
     */
    public function testCreatesProjectAndDerivesCurrentRoles(): void
    {
        $admin = $this->signInAdministrator();
        $projectId = $this->application->projects->createProject($admin, 1, '77', 'Project One');
        $user = $this->application->sessions->authenticate($this->signIn('9'));
        $this->role = 30;
        $permissions = $this->application->projects->checkPermissions($user, $projectId);
        self::assertTrue($permissions->canAllocateBuildNumber);
        self::assertFalse($permissions->canAdministerProject);
        $this->role = 40;
        self::assertTrue($this->application->projects->checkPermissions($user, $projectId)->canAdministerProject);
        $this->role = 20;
        self::assertFalse($this->application->projects->checkPermissions($user, $projectId)->canAllocateBuildNumber);
        self::assertSame(1, (int) $this->connection->query('SELECT next_build_number FROM projects')->fetchColumn());
        $this->expectException(AccountException::class);
        $this->application->projects->createProject($admin, 1, '77', 'Duplicate');
    }

    /**
     * Does not grant cross-provider project permission because external IDs match.
     *
     * @return void
     */
    public function testDeniesCrossProviderProjectAccess(): void
    {
        $admin = $this->signInAdministrator();
        $projectId = $this->application->projects->createProject($admin, 1, '77', 'Project One');
        $connectionId = $this->application->administration->saveConnection($admin, 'secondary', 'Other', true);
        $other = $this->application->sessions->authenticate($this->signIn('9', $connectionId));
        self::assertFalse($this->application->projects->checkPermissions($other, $projectId)->canAdministerProject);
    }

    /**
     * Propagates provider outages without changing counters or allocations.
     *
     * @return void
     */
    public function testFailsClosedOnProviderOutage(): void
    {
        $admin = $this->signInAdministrator();
        $projectId = $this->application->projects->createProject($admin, 1, '77', 'Project One');
        $user = $this->application->sessions->authenticate($this->signIn('9'));
        $this->isProviderUnavailable = true;
        $this->expectException(\Ordinal\Provider\ProviderUnavailableException::class);
        try {
            $this->application->projects->checkPermissions($user, $projectId);
        } finally {
            self::assertSame(0, (int) $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        }
    }

    /**
     * Observes two independent workers at the same user lock and proves only one rotated refresh occurs.
     *
     * @return void
     */
    public function testSerializesConcurrentRefreshes(): void
    {
        $admin = $this->signInAdministrator();
        $this->connection->exec('CREATE TABLE refresh_calls (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY)');
        $this->connection->exec("UPDATE provider_authorizations SET access_expires_at = clock_timestamp() - interval '1 second'");
        $workers = [];
        $this->connection->beginTransaction();
        $this->connection->query('SELECT id FROM users WHERE id = 1 FOR UPDATE');
        try {
            for ($index = 0; $index < 2; $index++) {
                $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';';
                $script .= 'echo Ordinal\\Tests\\Support\\AuthorizationWorker::refresh(' . var_export($this->schemaName, true) . ', ' . $admin->userId . ');';
                $process = proc_open([PHP_BINARY, '-r', $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'output' => $pipes[1], 'error' => $pipes[2]];
            }
            $statement = $this->connection->prepare("SELECT count(DISTINCT pid) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock'");
            $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
            do {
                $this->connection->query('SELECT pg_stat_clear_snapshot()');
                $statement->execute(['name' => $this->schemaName]);
                $blocked = (int) $statement->fetchColumn();
                if ($blocked === 2) {
                    break;
                }
                usleep(self::LOCK_POLL_MICROSECONDS);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $blocked, 'Both independent refresh requests must overlap at the user lock.');
            $this->connection->commit();
            foreach ($workers as &$worker) {
                $output = stream_get_contents($worker['output']);
                $error = stream_get_contents($worker['error']);
                fclose($worker['output']);
                fclose($worker['error']);
                self::assertSame(0, proc_close($worker['process']), $error);
                $worker['process'] = null;
                self::assertSame('rotated-access-8', $output);
            }
            unset($worker);
            self::assertSame(1, (int) $this->connection->query('SELECT count(*) FROM refresh_calls')->fetchColumn());
            self::assertSame(1, (int) $this->connection->query('SELECT refresh_version FROM provider_authorizations')->fetchColumn());
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
    }

    /**
     * Denies removal of the last additional administrator even before the configured bootstrap user has signed in.
     *
     * @return void
     */
    public function testProtectsLastAdministratorWithoutBootstrapSignIn(): void
    {
        $user = $this->application->sessions->authenticate($this->signIn('9'));
        $this->connection->exec('INSERT INTO instance_administrators (user_id) VALUES (' . $user->userId . ')');
        $this->expectException(AccountException::class);
        $this->application->administration->setAdministrator($user, $user->userId, false);
    }

    /**
     * Reauthentication rotates cookie/CSRF credentials without extending absolute expiry.
     *
     * @return void
     */
    public function testReauthenticatesOnlyTheSameIdentity(): void
    {
        $secret = $this->signIn();
        $session = $this->application->sessions->authenticate($secret);
        $attempt = $this->application->login->beginLogin(1, $session);
        parse_str((string) parse_url($attempt['authorizationUrl'], PHP_URL_QUERY), $query);
        $newSecret = $this->application->login->completeLogin($query['state'], '8', $attempt['browserSecret'], $secret);
        $newSession = $this->application->sessions->authenticate($newSecret);
        self::assertSame($session->id, $newSession->id);
        self::assertEquals($session->expiresAt, $newSession->expiresAt);
        self::assertNotSame($session->csrfToken, $newSession->csrfToken);
        $this->expectException(AuthenticationException::class);
        $this->application->sessions->authenticate($secret);
    }

    /**
     * Rejects reauthentication with a different immutable provider user without saving it.
     *
     * @return void
     */
    public function testRejectsAccountSwitchDuringReauthentication(): void
    {
        $secret = $this->signIn();
        $session = $this->application->sessions->authenticate($secret);
        $attempt = $this->application->login->beginLogin(1, $session);
        parse_str((string) parse_url($attempt['authorizationUrl'], PHP_URL_QUERY), $query);
        try {
            $this->application->login->completeLogin($query['state'], '9', $attempt['browserSecret'], $secret);
            self::fail('Reauthentication switched account.');
        } catch (AuthenticationException) {
            self::assertSame(1, (int) $this->connection->query('SELECT count(*) FROM users')->fetchColumn());
            self::assertSame($session->userId, $this->application->sessions->authenticate($secret)->userId);
        }
    }

    /**
     * Invalidates reusable credentials and browser sessions after a rejected refresh grant.
     *
     * @return void
     */
    public function testRevokesRejectedRefreshAuthorization(): void
    {
        $secret = $this->signIn();
        $session = $this->application->sessions->authenticate($secret);
        $this->connection->exec("UPDATE provider_authorizations SET access_expires_at = clock_timestamp() - interval '1 second'");
        $this->isRefreshRejected = true;
        try {
            $this->application->authorizations->getAuthorization($session->userId);
            self::fail('Rejected refresh was accepted.');
        } catch (\Ordinal\Provider\ProviderAuthenticationException) {
            self::assertNotNull($this->connection->query('SELECT revoked_at FROM provider_authorizations')->fetchColumn());
        }
        $this->expectException(AuthenticationException::class);
        $this->application->sessions->authenticate($secret);
    }

    /**
     * Denies stored credentials when ciphertext is copied between access and refresh fields.
     *
     * @return void
     */
    public function testRejectsSwappedEncryptedTokenFields(): void
    {
        $admin = $this->signInAdministrator();
        $this->connection->exec('UPDATE provider_authorizations SET encrypted_access_token = encrypted_refresh_token');
        $this->expectException(AuthenticationException::class);
        $this->application->authorizations->getAuthorization($admin->userId);
    }

    /**
     * Invalidates sessions and repository checks when their provider connection is disabled.
     *
     * @return void
     */
    public function testRejectsDisabledConnections(): void
    {
        $admin = $this->signInAdministrator();
        $id = $this->application->administration->saveConnection($admin, 'secondary', 'Other', true);
        $secret = $this->signIn('9', $id);
        $this->application->administration->saveConnection($admin, 'secondary', 'Other', false);
        $this->expectException(AuthenticationException::class);
        $this->application->sessions->authenticate($secret);
    }

    /**
     * Does not let a delayed rejection revoke credentials saved by a newer sign-in.
     *
     * @return void
     */
    public function testPreservesNewerAuthorizationOnOldRejection(): void
    {
        $admin = $this->signInAdministrator();
        $newSecret = $this->signIn();
        $repository = new \Ordinal\Repository\AuthorizationRepository($this->connection, new \Ordinal\Security\TokenCipher(str_repeat('ab', 32)));
        $this->connection->beginTransaction();
        try {
            $repository->lockUser($admin->userId);
            $repository->revokeAuthorization($admin->userId, 0);
            $this->connection->commit();
        } finally {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
        }
        self::assertNull($this->connection->query('SELECT revoked_at FROM provider_authorizations')->fetchColumn());
        self::assertSame($admin->userId, $this->application->sessions->authenticate($newSecret)->userId);
    }

    /**
     * Rolls back identity, ciphertext, session, and bootstrap grants when sign-in auditing fails.
     *
     * @return void
     */
    public function testRollsBackFailedSignInPersistence(): void
    {
        $this->connection->exec("ALTER TABLE audit_events ADD CONSTRAINT reject_sign_in CHECK (action <> 'provider_sign_in')");
        try {
            $this->signIn();
            self::fail('Failed audit was ignored.');
        } catch (\PDOException) {
            foreach (['users', 'provider_authorizations', 'browser_sessions', 'instance_administrators'] as $table) {
                self::assertSame(0, (int) $this->connection->query('SELECT count(*) FROM ' . $table)->fetchColumn());
            }
        }
    }
}
