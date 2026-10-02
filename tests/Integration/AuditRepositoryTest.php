<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Http\Router;
use Ordinal\Tests\Support\AccountFixture;
use PHPUnit\Framework\TestCase;

/** Verifies allocation outcomes, trusted caller relations and complete secret exclusion in persisted events. */
final class AuditRepositoryTest extends TestCase
{
    /** Shares this test's guarded application setup. */
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
     * Records each allocated/replayed/invalid/revoked request without copying secrets or request bodies.
     *
     * @return void
     */
    public function testRecordsEveryOutcomeWithVerifiedCiIdentity(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        $token = $app->automation->createToken($session, $project, 'CI');
        $router = new Router($app);
        $path = '/api/projects/' . $project . '/build-numbers';
        $body = '{"requestId":"11111111-1111-4111-8111-111111111111"}';
        foreach ([200, 200] as $status) {
            self::assertSame($status, $router->dispatch('POST', $path, $body, 'Bearer ' . $token['token'], 'application/json', [], true)->statusCode);
        }
        self::assertSame(400, $router->dispatch('POST', $path, 'invalid-private-body', 'Bearer private-header-secret', 'application/json', [], true)->statusCode);
        self::assertSame(401, $router->dispatch('POST', $path, $body, 'Basic private-header-secret', 'application/json', [], true)->statusCode);
        self::assertSame(405, $router->dispatch('GET', $path, isSecure: true)->statusCode);
        $app->automation->revokeToken($session, $token['id']);
        self::assertSame(401, $router->dispatch('POST', $path, $body, 'Bearer ' . $token['token'], 'application/json', [], true)->statusCode);
        $events = $this->fixture->connection->query("SELECT * FROM audit_events WHERE action = 'allocate_build_number' ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(6, $events);
        self::assertSame(['allocated', 'replayed', 'INVALID_REQUEST', 'INVALID_AUTHENTICATION', 'METHOD_NOT_ALLOWED', 'INVALID_AUTHENTICATION'], array_column($events, 'outcome'));
        self::assertSame($token['id'], $events[5]['automation_token_id']);
        self::assertNull($events[2]['automation_token_id']);
        foreach ([$token['token'], 'invalid-private-body', 'private-header-secret'] as $secret) {
            self::assertStringNotContainsString($secret, json_encode($events, JSON_THROW_ON_ERROR));
        }
        self::assertSame(2, (int) $this->fixture->connection->query('SELECT next_build_number FROM projects')->fetchColumn());
    }

    /**
     * Does not invent caller/project relations from unknown tokens or missing project IDs.
     *
     * @return void
     */
    public function testRejectsForgedAndStaleFailureAttribution(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        $token = $app->automation->createToken($session, $project, 'CI');
        $router = new Router($app);
        $body = '{"requestId":"11111111-1111-4111-8111-111111111111"}';
        self::assertSame(200, $router->dispatch('POST', '/api/projects/' . $project . '/build-numbers', $body, 'Bearer ' . $token['token'], 'application/json', [], true)->statusCode);
        self::assertSame(401, $router->dispatch('POST', '/api/projects/' . $project . '/build-numbers', $body, 'Bearer ' . $token['token'], 'application/json')->statusCode);
        self::assertSame(404, $router->dispatch('POST', '/api/projects/9999/build-numbers', $body, 'Bearer ' . $token['token'], 'application/json', [], true)->statusCode);
        $events = $this->fixture->connection->query("SELECT * FROM audit_events WHERE action = 'allocate_build_number' AND allocation_id IS NULL ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $events);
        self::assertNull($events[0]['automation_token_id']);
        self::assertNull($events[1]['automation_token_id']);
        self::assertNull($events[1]['project_id']);
    }

    /**
     * Expiration failures retain verified credential attribution and a fixed expiration reason.
     *
     * @return void
     */
    public function testRecordsCredentialExpirationWithoutSecrets(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        $token = $app->automation->createToken($session, $project, 'CI');
        $this->fixture->connection->exec("UPDATE automation_tokens SET created_at = clock_timestamp() - interval '91 days', expires_at = clock_timestamp() - interval '1 day'");
        $response = (new Router($app))->dispatch('POST', '/api/projects/' . $project . '/build-numbers', '{"requestId":"11111111-1111-4111-8111-111111111111"}', 'Bearer ' . $token['token'], 'application/json', [], true);
        self::assertSame(401, $response->statusCode);
        $details = json_decode($this->fixture->connection->query("SELECT details FROM audit_events WHERE action = 'allocate_build_number'")->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('CREDENTIAL_EXPIRED', $details['reason'] ?? null);
    }

    /**
     * Logout and its verified user audit commit together, including rollback if audit persistence fails.
     *
     * @return void
     */
    public function testAuditsLogoutAtomically(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $this->fixture->connection->exec("ALTER TABLE audit_events ADD CONSTRAINT reject_logout CHECK (action <> 'browser_logout') NOT VALID");
        try { $app->sessions->revokeSession($session); self::fail('Audit failure committed logout.'); }
        catch (\PDOException) { self::assertSame($session->id, $app->sessions->requireActiveSession($session)->id); }
        $this->fixture->connection->exec('ALTER TABLE audit_events DROP CONSTRAINT reject_logout');
        $app->sessions->revokeSession($session);
        self::assertSame($session->userId, $this->fixture->connection->query("SELECT user_id FROM audit_events WHERE action = 'browser_logout'")->fetchColumn());
    }
}
