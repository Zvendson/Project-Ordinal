<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Database\MigrationRunner;
use Ordinal\Tests\Support\TestDatabase;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/** Verifies application relationships and storage safeguards against PostgreSQL. */
final class ApplicationSchemaTest extends TestCase
{
    /** Provides the guarded test database connection. */
    private PDO    $connection;
    /** Names the isolated schema created for this test. */
    private string $schemaName;

    /**
     * Creates a fresh schema and applies the actual application migrations.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection = TestDatabase::createConnection();
        $this->schemaName = 'application_test_' . bin2hex(random_bytes(8));
        $this->connection->exec('CREATE SCHEMA ' . $this->schemaName);
        $this->connection->exec('SET search_path TO ' . $this->schemaName);
        (new MigrationRunner($this->connection))->applyMigrations(dirname(__DIR__, 2) . '/database/migrations');
    }

    /**
     * Removes only the randomly named schema owned by this test.
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
     * Creates all application tables and skips an unchanged second migration run.
     *
     * @return void
     */
    public function testCreatesSchemaAndAppliesOnce(): void
    {
        $tables = $this->connection->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['allocations', 'audit_events', 'automation_tokens', 'browser_sessions',
            'device_credentials', 'devices', 'instance_administrators', 'instance_settings',
            'projects', 'provider_authorizations', 'provider_connections', 'schema_migrations', 'users'], $tables);
        self::assertSame(0, (new MigrationRunner($this->connection))->applyMigrations(dirname(__DIR__, 2) . '/database/migrations'));
        $settings = $this->connection->query('SELECT * FROM instance_settings')->fetch();
        self::assertSame(30, $settings['device_lifetime_days']);
        self::assertSame(90, $settings['automation_lifetime_days']);
        self::assertSame(30, $settings['browser_idle_minutes']);
        self::assertSame(720, $settings['browser_absolute_minutes']);
        self::assertTrue($settings['is_authentication_required']);
        self::assertFalse($settings['is_automation_without_expiration_allowed']);
    }

    /**
     * Keeps equal provider user IDs separate and rejects duplicates within one connection.
     *
     * @return void
     */
    public function testSeparatesProviderIdentities(): void
    {
        $this->connection->exec("INSERT INTO provider_connections (provider_kind, server_url, name) VALUES ('github', 'https://github.com', 'GitHub'), ('gitlab', 'https://gitlab.com', 'GitLab')");
        $this->connection->exec("INSERT INTO users (provider_connection_id, provider_user_id, display_name) VALUES (1, '42', 'First'), (2, '42', 'Second')");
        self::assertSame(2, $this->connection->query('SELECT count(*) FROM users')->fetchColumn());
        $this->assertRejectsSql("INSERT INTO users (provider_connection_id, provider_user_id, display_name) VALUES (1, '42', 'Duplicate')", '23505');
        $this->assertRejectsSql("INSERT INTO users (provider_connection_id, provider_user_id, display_name) VALUES (99, '42', 'Missing')", '23503');
    }

    /**
     * Preserves allocations and actor relationships when audit events are deleted.
     *
     * @return void
     */
    public function testPreservesHistoryRelationships(): void
    {
        $this->createProjectFixtures();
        $this->connection->exec("INSERT INTO automation_tokens (project_id, created_by_user_id, name, secret_hash, expires_at) VALUES (1, 1, 'CI', repeat('a', 64), CURRENT_TIMESTAMP + INTERVAL '90 days')");
        $this->connection->exec("INSERT INTO allocations (project_id, request_id, build_number, caller_kind, automation_token_id) VALUES (1, '11111111-1111-4111-8111-111111111111', 1, 'automation', 1)");
        $this->connection->exec("INSERT INTO audit_events (project_id, allocation_id, automation_token_id, action, outcome) VALUES (1, 1, 1, 'allocation', 'success')");
        $this->connection->exec("UPDATE automation_tokens SET name = 'Renamed', secret_hash = repeat('b', 64) WHERE id = 1");
        self::assertSame(1, $this->connection->query('SELECT automation_token_id FROM audit_events')->fetchColumn());
        $this->assertRejectsSql('DELETE FROM automation_tokens WHERE id = 1', '23001');
        $this->assertRejectsSql('DELETE FROM projects WHERE id = 1', '23001');
        $this->assertRejectsSql('DELETE FROM users WHERE id = 1', '23001');
        $this->connection->exec('DELETE FROM audit_events');
        self::assertSame(1, $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        $this->connection->exec('UPDATE projects SET archived_at = CURRENT_TIMESTAMP WHERE id = 1');
        self::assertSame(1, $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
    }

    /**
     * Rejects allocations with mixed actors or credentials bound to another project/user.
     *
     * @return void
     */
    public function testRejectsInvalidCallerRelationships(): void
    {
        $this->createProjectFixtures();
        $this->connection->exec("INSERT INTO automation_tokens (project_id, created_by_user_id, name, secret_hash, expires_at) VALUES (1, 1, 'CI', repeat('a', 64), CURRENT_TIMESTAMP + INTERVAL '90 days')");
        $this->assertRejectsSql("INSERT INTO allocations (project_id, request_id, build_number, caller_kind, user_id, automation_token_id) VALUES (1, '11111111-1111-4111-8111-111111111111', 1, 'user', 1, 1)", '23514');
        $this->assertRejectsSql("INSERT INTO allocations (project_id, request_id, build_number, caller_kind, automation_token_id) VALUES (2, '11111111-1111-4111-8111-111111111111', 1, 'automation', 1)", '23503');
        $this->connection->exec("INSERT INTO devices (user_id, name) VALUES (1, 'Laptop')");
        $this->connection->exec("INSERT INTO device_credentials (device_id, user_id, project_id, secret_hash, lifetime_days) VALUES (1, 1, 1, repeat('c', 64), 0)");
        $this->assertRejectsSql("INSERT INTO allocations (project_id, request_id, build_number, caller_kind, user_id, device_credential_id) VALUES (2, '11111111-1111-4111-8111-111111111111', 1, 'user', 1, 1)", '23503');
        $this->assertRejectsSql("INSERT INTO device_credentials (device_id, user_id, project_id, secret_hash, lifetime_days) VALUES (1, 2, 1, repeat('d', 64), 0)", '23503');
    }

    /**
     * Rejects invalid credential hashes, lifetime values, and browser session timestamps.
     *
     * @return void
     */
    public function testRejectsInvalidSecurityStorage(): void
    {
        $this->createProjectFixtures();
        $this->assertRejectsSql("INSERT INTO automation_tokens (project_id, created_by_user_id, name, secret_hash, expires_at) VALUES (1, 1, 'CI', 'raw-secret', CURRENT_TIMESTAMP + INTERVAL '90 days')", '23514');
        $this->assertRejectsSql('UPDATE instance_settings SET browser_idle_minutes = 0', '23514');
        $this->assertRejectsSql('UPDATE instance_settings SET device_lifetime_days = -2', '23514');
        $this->assertRejectsSql("INSERT INTO browser_sessions (user_id, secret_hash, csrf_token, expires_at) VALUES (1, repeat('a', 64), repeat('b', 64), CURRENT_TIMESTAMP - INTERVAL '1 minute')", '23514');
        $this->assertRejectsSql('INSERT INTO instance_settings (id) VALUES (2)', '23514');
    }

    /**
     * Supports assigned lifetimes and binds consumed allocations to their original credential.
     *
     * @return void
     */
    public function testStoresCredentialLifetimeAndConsumption(): void
    {
        $this->createProjectFixtures();
        $this->connection->exec("INSERT INTO devices (user_id, name) VALUES (1, 'Laptop')");
        $this->connection->exec("INSERT INTO device_credentials (device_id, user_id, project_id, secret_hash, lifetime_days) VALUES (1, 1, 1, repeat('a', 64), 0), (1, 1, 1, repeat('b', 64), 0), (1, 1, 1, repeat('c', 64), -1)");
        $this->connection->exec("INSERT INTO device_credentials (device_id, user_id, project_id, secret_hash, lifetime_days, expires_at) VALUES (1, 1, 1, repeat('d', 64), 30, CURRENT_TIMESTAMP + INTERVAL '30 days')");
        $this->connection->exec("INSERT INTO allocations (project_id, request_id, build_number, caller_kind, user_id, device_credential_id) VALUES (1, '11111111-1111-4111-8111-111111111111', 1, 'user', 1, 1), (1, '22222222-2222-4222-8222-222222222222', 2, 'user', 1, 2)");
        $this->assertRejectsSql('UPDATE device_credentials SET consumed_allocation_id = 2 WHERE id = 1', '23503');
        $this->connection->exec('UPDATE device_credentials SET consumed_allocation_id = 1 WHERE id = 1');
        self::assertSame(1, $this->connection->query('SELECT consumed_allocation_id FROM device_credentials WHERE id = 1')->fetchColumn());
        $this->connection->exec('UPDATE instance_settings SET device_lifetime_days = 60');
        self::assertSame(30, $this->connection->query('SELECT lifetime_days FROM device_credentials WHERE id = 4')->fetchColumn());
        $this->assertRejectsSql("INSERT INTO device_credentials (device_id, user_id, project_id, secret_hash, lifetime_days) VALUES (1, 1, 1, repeat('e', 64), 30)", '23514');
        $this->assertRejectsSql("INSERT INTO device_credentials (device_id, user_id, project_id, secret_hash, lifetime_days, expires_at) VALUES (1, 1, 1, repeat('f', 64), -1, CURRENT_TIMESTAMP + INTERVAL '1 day')", '23514');
    }

    /**
     * Stores provider ciphertext, valid session state, administration, and failed audit events.
     *
     * @return void
     */
    public function testStoresAuthenticationAndAdministrationRecords(): void
    {
        $this->createProjectFixtures();
        $this->connection->exec('INSERT INTO instance_administrators (user_id, granted_by_user_id) VALUES (1, 2)');
        $this->connection->exec("INSERT INTO provider_authorizations (user_id, encrypted_access_token, encrypted_refresh_token) VALUES (1, decode('0102', 'hex'), decode('0304', 'hex'))");
        $this->connection->exec("INSERT INTO browser_sessions (user_id, secret_hash, csrf_token, expires_at) VALUES (1, repeat('a', 64), repeat('b', 64), CURRENT_TIMESTAMP + INTERVAL '720 minutes')");
        $this->connection->exec("INSERT INTO audit_events (project_id, user_id, action, outcome) VALUES (1, 1, 'allocation', 'denied')");
        self::assertSame(1, $this->connection->query('SELECT user_id FROM instance_administrators')->fetchColumn());
        self::assertSame(0, $this->connection->query('SELECT refresh_version FROM provider_authorizations')->fetchColumn());
        self::assertSame(30, $this->connection->query('SELECT idle_minutes FROM browser_sessions')->fetchColumn());
        self::assertNull($this->connection->query('SELECT allocation_id FROM audit_events')->fetchColumn());
        self::assertSame('UTC', $this->connection->query('SHOW TIME ZONE')->fetchColumn());
    }

    /**
     * Creates two projects and users for relationship checks.
     *
     * @return void
     */
    private function createProjectFixtures(): void
    {
        $this->connection->exec("INSERT INTO provider_connections (provider_kind, server_url, name) VALUES ('github', 'https://github.com', 'GitHub')");
        $this->connection->exec("INSERT INTO users (provider_connection_id, provider_user_id, display_name) VALUES (1, '42', 'First'), (1, '43', 'Second')");
        $this->connection->exec("INSERT INTO projects (provider_connection_id, provider_repository_id, name) VALUES (1, '100', 'First'), (1, '101', 'Second')");
    }

    /**
     * Verifies the PostgreSQL SQLSTATE for a rejected operation.
     *
     * @param string $sql
     * @param string $expectedState
     * @return void
     */
    private function assertRejectsSql(string $sql, string $expectedState): void
    {
        try {
            $this->connection->exec($sql);
            self::fail('Expected PostgreSQL to reject the operation.');
        } catch (PDOException $exception) {
            self::assertSame($expectedState, $exception->getCode());
        }
    }
}
