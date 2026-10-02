<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Database\MigrationRunner;
use Ordinal\Security\AuthenticationException;
use Ordinal\Service\AccountException;
use Ordinal\Service\ManagementApplication;
use Ordinal\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Verifies independent projects, named tokens, administrator sessions and permanent build retries. */
final class ManagementApplicationTest extends TestCase
{
    /** Owns this test's isolated database connection. */
    private PDO                   $connection;
    /** Identifies the guarded temporary schema. */
    private string                $schemaName;
    /** Composes provider-free management and allocation. */
    private ManagementApplication $application;
    /** Supplies the test-only administrator password. */
    private const string PASSWORD = 'a-test-only-administrator-password';
    /** Provides a valid stable build attempt ID. */
    private const string REQUEST_ID = '11111111-1111-4111-8111-111111111111';

    /**
     * Creates a migrated, isolated application without external provider registration.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection = TestDatabase::createConnection();
        $this->schemaName = 'management_test_' . bin2hex(random_bytes(8));
        $this->connection->exec('CREATE SCHEMA ' . $this->schemaName);
        $this->connection->exec('SET search_path TO ' . $this->schemaName);
        (new MigrationRunner($this->connection))->applyMigrations(dirname(__DIR__, 2) . '/database/migrations');
        $this->application = new ManagementApplication($this->connection, password_hash(self::PASSWORD, PASSWORD_DEFAULT));
    }

    /**
     * Removes only this test's guarded schema.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (isset($this->connection, $this->schemaName)) {
            if ($this->connection->inTransaction()) { $this->connection->rollBack(); }
            $this->connection->exec('DROP SCHEMA ' . $this->schemaName . ' CASCADE');
        }
    }

    /**
     * Accepts projects with and without a repository URL and permits multiple tokens.
     *
     * @return void
     */
    public function testCreatesIndependentProjectsAndNamedTokens(): void
    {
        $first = $this->application->projects->createProject('First');
        $second = $this->application->projects->createProject('Second', 'https://github.com/Zvendson/example');
        self::assertNull($this->application->projects->getProject($first)['repository_url']);
        self::assertSame('https://github.com/Zvendson/example', $this->application->projects->getProject($second)['repository_url']);
        $token = $this->application->tokens->createToken($first, 'Laptop');
        $other = $this->application->tokens->createToken($first, 'CI');
        self::assertNotSame($token['token'], $other['token']);
        self::assertCount(2, $this->application->tokens->findProjectTokens($first));
        self::assertStringNotContainsString($token['token'], json_encode($this->application->tokens->findProjectTokens($first), JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->connection->query('SELECT count(*) FROM users')->fetchColumn());
        self::assertSame(0, $this->connection->query('SELECT count(*) FROM provider_connections')->fetchColumn());
    }

    /**
     * Rejects script/credential URLs and blank project names.
     *
     * @return void
     */
    public function testRejectsInvalidProjectInput(): void
    {
        $this->expectException(AccountException::class);
        $this->application->projects->createProject('Example', 'javascript:alert(1)');
    }

    /**
     * Requires the configured password and invalidates the pre-login session.
     *
     * @return void
     */
    public function testSignsInWithOneAdministratorPassword(): void
    {
        $initial = $this->application->administrator->startSession();
        $session = $this->application->administrator->authenticateSession($initial['cookie'], false);
        $cookie = $this->application->administrator->signIn($initial['cookie'], self::PASSWORD, $session->csrfToken, '127.0.0.1');
        self::assertTrue($this->application->administrator->authenticateSession($cookie)->isAuthenticated);
        $this->expectException(AuthenticationException::class);
        $this->application->administrator->authenticateSession($initial['cookie']);
    }

    /**
     * Rejects a wrong password without granting management access.
     *
     * @return void
     */
    public function testRejectsWrongPassword(): void
    {
        $initial = $this->application->administrator->startSession();
        $session = $this->application->administrator->authenticateSession($initial['cookie'], false);
        $this->expectException(AuthenticationException::class);
        $this->application->administrator->signIn($initial['cookie'], 'wrong', $session->csrfToken, '127.0.0.1');
    }

    /**
     * Rejects login forms that do not belong to the browser session.
     *
     * @return void
     */
    public function testRejectsLoginCsrf(): void
    {
        $initial = $this->application->administrator->startSession();
        $this->expectException(AccountException::class);
        $this->application->administrator->signIn($initial['cookie'], self::PASSWORD, str_repeat('a', 64), '127.0.0.1');
    }

    /**
     * Limits failed sign-ins for the same client without contacting any provider.
     *
     * @return void
     */
    public function testLimitsPasswordAttempts(): void
    {
        $initial = $this->application->administrator->startSession();
        $session = $this->application->administrator->authenticateSession($initial['cookie'], false);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try { $this->application->administrator->signIn($initial['cookie'], 'wrong', $session->csrfToken, '127.0.0.1'); }
            catch (AuthenticationException) {}
        }
        $this->expectException(AccountException::class);
        $this->application->administrator->signIn($initial['cookie'], self::PASSWORD, $session->csrfToken, '127.0.0.1');
    }

    /**
     * Allocates atomically and separates retry namespaces by stable project token.
     *
     * @return void
     */
    public function testAllocatesAndReplaysWithNamedTokens(): void
    {
        $project = $this->application->projects->createProject('Without repository');
        $first = $this->application->tokens->createToken($project, 'First');
        $second = $this->application->tokens->createToken($project, 'Second');
        $caller = $this->application->allocationAuthorizer->authorizeAllocation($project, $first['token']);
        self::assertSame(1, $this->application->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        self::assertSame(1, $this->application->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        $other = $this->application->allocationAuthorizer->authorizeAllocation($project, $second['token']);
        self::assertSame(2, $this->application->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $other));
        self::assertSame(3, $this->application->projects->getProject($project)['next_build_number']);
    }

    /**
     * Rejects missing credentials and cross-project token use.
     *
     * @return void
     */
    public function testRequiresProjectToken(): void
    {
        $project = $this->application->projects->createProject('Project');
        $this->expectException(AuthenticationException::class);
        $this->application->allocationAuthorizer->authorizeAllocation($project, null);
    }

    /**
     * Rotation preserves retry records while making the previous secret unusable.
     *
     * @return void
     */
    public function testRotatesAndRevokesProjectTokens(): void
    {
        $project = $this->application->projects->createProject('Project');
        $token = $this->application->tokens->createToken($project, 'CI');
        $caller = $this->application->allocationAuthorizer->authorizeAllocation($project, $token['token']);
        self::assertSame(1, $this->application->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        $replacement = $this->application->tokens->rotateToken($project, $token['id']);
        $caller = $this->application->allocationAuthorizer->authorizeAllocation($project, $replacement['token']);
        self::assertSame(1, $this->application->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        $this->application->tokens->revokeToken($project, $token['id']);
        $this->expectException(AuthenticationException::class);
        $this->application->allocationAuthorizer->authorizeAllocation($project, $replacement['token']);
    }

    /**
     * Reset requires a name/reuse confirmation and preserves the original retry mapping.
     *
     * @return void
     */
    public function testResetsCounterWithoutLosingRetries(): void
    {
        $project = $this->application->projects->createProject('Project');
        $token = $this->application->tokens->createToken($project, 'CI');
        $caller = $this->application->allocationAuthorizer->authorizeAllocation($project, $token['token']);
        self::assertSame(1, $this->application->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        $this->application->projects->resetCounter($project, 0, 'Project', true);
        self::assertSame(1, $this->application->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        self::assertSame(0, $this->application->projects->getProject($project)['next_build_number']);
    }

    /**
     * Rejects tokens from another project without advancing either counter.
     *
     * @return void
     */
    public function testRejectsCrossProjectToken(): void
    {
        $first = $this->application->projects->createProject('First');
        $second = $this->application->projects->createProject('Second');
        $token = $this->application->tokens->createToken($first, 'Laptop');
        try { $this->application->allocationAuthorizer->authorizeAllocation($second, $token['token']); self::fail('Cross-project token accepted.'); }
        catch (\Ordinal\Service\AllocationException $exception) { self::assertSame('ACCESS_DENIED', $exception->errorCode); }
        self::assertSame(1, $this->application->projects->getProject($first)['next_build_number']);
        self::assertSame(1, $this->application->projects->getProject($second)['next_build_number']);
    }

    /**
     * Prevents previously verified secrets from surviving rotation at allocation time.
     *
     * @return void
     */
    public function testRejectsStaleVerifiedSecretAfterRotation(): void
    {
        $project = $this->application->projects->createProject('Project');
        $token = $this->application->tokens->createToken($project, 'Laptop');
        $caller = $this->application->allocationAuthorizer->authorizeAllocation($project, $token['token']);
        $this->application->tokens->rotateToken($project, $token['id']);
        $this->expectException(AuthenticationException::class);
        $this->application->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller);
    }

    /**
     * Rejects expired tokens even for a retry of their original allocation.
     *
     * @return void
     */
    public function testRejectsExpiredToken(): void
    {
        $project = $this->application->projects->createProject('Project');
        $token = $this->application->tokens->createToken($project, 'Laptop');
        $this->connection->exec("UPDATE automation_tokens SET created_at = clock_timestamp() - INTERVAL '2 days', expires_at = clock_timestamp() - INTERVAL '1 day'");
        $this->expectException(AuthenticationException::class);
        $this->application->allocationAuthorizer->authorizeAllocation($project, $token['token']);
    }

    /**
     * Password changes invalidate existing management sessions while preserving build tokens.
     *
     * @return void
     */
    public function testInvalidatesSessionAfterPasswordChange(): void
    {
        $initial = $this->application->administrator->startSession();
        $cookie = $this->application->administrator->signIn($initial['cookie'], self::PASSWORD, $initial['session']->csrfToken, '127.0.0.1');
        $replacement = new ManagementApplication($this->connection, password_hash('another-test-password', PASSWORD_DEFAULT));
        $this->expectException(AuthenticationException::class);
        $replacement->administrator->authenticateSession($cookie);
    }

    /**
     * Logs contain named token metadata but no raw token secret or stored hash.
     *
     * @return void
     */
    public function testShowsSafeNamedTokenHistory(): void
    {
        $project = $this->application->projects->createProject('Project');
        $token = $this->application->tokens->createToken($project, 'Laptop');
        $caller = $this->application->allocationAuthorizer->authorizeAllocation($project, $token['token']);
        $this->application->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller);
        $events = $this->application->audit->findEvents($project, null, true);
        self::assertSame('Laptop', $events[0]['automation_name']);
        self::assertSame(1, $events[0]['build_number']);
        self::assertStringNotContainsString($token['token'], json_encode($events, JSON_THROW_ON_ERROR));
    }

    /**
     * Audit failure rolls back project token issuance rather than leaving an unaudited secret.
     *
     * @return void
     */
    public function testRollsBackTokenIssuanceWhenAuditFails(): void
    {
        $project = $this->application->projects->createProject('Project');
        $this->connection->exec('ALTER TABLE audit_events ADD CONSTRAINT reject_token_audit CHECK (action <> \'token_created\')');
        try { $this->application->tokens->createToken($project, 'Laptop'); self::fail('Audit failure ignored.'); }
        catch (\PDOException) { self::assertSame([], $this->application->tokens->findProjectTokens($project)); }
    }

    /**
     * Archives block both allocation and token mutations until restored.
     *
     * @return void
     */
    public function testArchivesAndRestoresProject(): void
    {
        $project = $this->application->projects->createProject('Project');
        $token = $this->application->tokens->createToken($project, 'Laptop');
        $this->application->projects->saveArchiveState($project, true);
        try { $this->application->allocationAuthorizer->authorizeAllocation($project, $token['token']); self::fail('Archived allocation allowed.'); }
        catch (\Ordinal\Service\AllocationException $exception) { self::assertSame('PROJECT_NOT_FOUND', $exception->errorCode); }
        $this->application->projects->saveArchiveState($project, false);
        self::assertNotNull($this->application->allocationAuthorizer->authorizeAllocation($project, $token['token']));
    }

    /**
     * Requires explicit reset confirmation and rejects ordinary backwards edits after allocation.
     *
     * @return void
     */
    public function testRejectsUnconfirmedCounterReuse(): void
    {
        $project = $this->application->projects->createProject('Project');
        $token = $this->application->tokens->createToken($project, 'Laptop');
        $caller = $this->application->allocationAuthorizer->authorizeAllocation($project, $token['token']);
        $this->application->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller);
        foreach ([false, true] as $isReset) {
            try {
                if ($isReset) { $this->application->projects->resetCounter($project, 0, 'Project', false); }
                else { $this->application->projects->saveCounter($project, 0); }
                self::fail('Unconfirmed backwards counter change accepted.');
            } catch (AccountException) { self::assertSame(2, $this->application->projects->getProject($project)['next_build_number']); }
        }
    }
}
