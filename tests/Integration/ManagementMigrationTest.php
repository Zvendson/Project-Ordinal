<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Database\MigrationRunner;
use Ordinal\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Verifies upgrading an existing installation preserves counters, tokens and permanent retries. */
final class ManagementMigrationTest extends TestCase
{
    /**
     * Applies the new migration on real legacy data without altering earlier migration checksums.
     *
     * @return void
     */
    public function testPreservesExistingProjectAndRetryRecords(): void
    {
        $root = dirname(__DIR__, 2);
        $schema = 'upgrade_test_' . bin2hex(random_bytes(8));
        $directory = $root . '/.local/' . $schema;
        mkdir($directory, 0700, true);
        $connection = TestDatabase::createConnection();
        $connection->exec('CREATE SCHEMA ' . $schema);
        $connection->exec('SET search_path TO ' . $schema);
        try {
            foreach (glob($root . '/database/migrations/*.sql') as $file) {
                if (!str_contains(basename($file), 'add_local_management')) { copy($file, $directory . '/' . basename($file)); }
            }
            self::assertSame(9, (new MigrationRunner($connection))->applyMigrations($directory));
            $connection->exec("INSERT INTO provider_connections (provider_kind, server_url, name) VALUES ('github', 'https://github.com', 'Previous');
                INSERT INTO users (provider_connection_id, provider_user_id, display_name) VALUES (1, '1', 'Previous');
                INSERT INTO projects (provider_connection_id, provider_repository_id, name, next_build_number, has_allocated_build_number) VALUES (1, '77', 'Existing', 43, TRUE);
                INSERT INTO automation_tokens (project_id, created_by_user_id, name, secret_hash) VALUES (1, 1, 'Existing token', repeat('a', 64));
                INSERT INTO allocations (project_id, request_id, build_number, caller_kind, automation_token_id) VALUES (1, '11111111-1111-4111-8111-111111111111', 42, 'automation', 1)");
            self::assertSame(1, (new MigrationRunner($connection))->applyMigrations($root . '/database/migrations'));
            self::assertSame(43, $connection->query('SELECT next_build_number FROM projects WHERE id = 1')->fetchColumn());
            self::assertSame(42, $connection->query('SELECT build_number FROM allocations')->fetchColumn());
            self::assertSame(str_repeat('a', 64), $connection->query('SELECT secret_hash FROM automation_tokens')->fetchColumn());
            $connection->exec("INSERT INTO projects (name) VALUES ('Without repository')");
            self::assertSame(2, $connection->query('SELECT count(*) FROM projects')->fetchColumn());
        } finally {
            if ($connection->inTransaction()) { $connection->rollBack(); }
            $connection->exec('DROP SCHEMA ' . $schema . ' CASCADE');
            foreach (glob($directory . '/*.sql') as $file) { unlink($file); }
            rmdir($directory);
        }
    }
}
