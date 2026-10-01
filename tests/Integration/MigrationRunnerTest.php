<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Database\MigrationRunner;
use Ordinal\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies the behavior of MigrationRunner.
 */
final class MigrationRunnerTest extends TestCase
{
    /** Provides the database connection used by these operations. */
    private PDO    $connection;
    /** Names the randomly generated schema owned by the current test. */
    private string $schemaName;
    /** Locates SQL fixtures created for the current test. */
    private string $migrationDirectory;

    /**
     * Creates an isolated schema and migration fixture directory for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection         = TestDatabase::createConnection();
        $this->schemaName         = 'migration_test_' . bin2hex(random_bytes(8));
        $this->migrationDirectory = dirname(__DIR__, 2) . '/.local/' . $this->schemaName;
        mkdir($this->migrationDirectory, recursive: true);
        $this->connection->exec('CREATE SCHEMA ' . $this->schemaName);
        $this->connection->exec('SET search_path TO ' . $this->schemaName);
    }

    /**
     * Removes this test's isolated schema and temporary SQL fixtures.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (isset($this->connection, $this->schemaName)) {
            $this->connection->exec('DROP SCHEMA IF EXISTS ' . $this->schemaName . ' CASCADE');
        }
        if (isset($this->migrationDirectory) && is_dir($this->migrationDirectory)) {
            foreach (glob($this->migrationDirectory . '/*.sql') as $filePath) {
                unlink($filePath);
            }
            rmdir($this->migrationDirectory);
        }
    }

    /**
     * Verifies: applies migrations once in filename order.
     *
     * @return void
     */
    public function testAppliesMigrationsOnceInFilenameOrder(): void
    {
        file_put_contents($this->migrationDirectory . '/20261002000200_insert.sql', 'INSERT INTO example (value) VALUES (7);');
        file_put_contents($this->migrationDirectory . '/20261002000100_create.sql', 'CREATE TABLE example (value INTEGER NOT NULL);');
        $runner = new MigrationRunner($this->connection);

        self::assertSame(2, $runner->applyMigrations($this->migrationDirectory));
        self::assertSame(0, $runner->applyMigrations($this->migrationDirectory));
        self::assertSame(7, $this->connection->query('SELECT value FROM example')->fetchColumn());
        self::assertSame(2, $this->connection->query('SELECT count(*) FROM schema_migrations')->fetchColumn());
    }

    /**
     * Verifies: rolls back the entire batch on invalid sql.
     *
     * @return void
     */
    public function testRollsBackTheEntireBatchOnInvalidSql(): void
    {
        file_put_contents($this->migrationDirectory . '/20261002000100_create.sql', 'CREATE TABLE example (value INTEGER);');
        file_put_contents($this->migrationDirectory . '/20261002000200_fail.sql', 'INVALID SQL;');

        try {
            (new MigrationRunner($this->connection))->applyMigrations($this->migrationDirectory);
            self::fail('Expected the migration failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Database migrations failed. No pending migrations were applied.', $exception->getMessage());
        }

        self::assertNull($this->connection->query("SELECT to_regclass('example')")->fetchColumn());
        self::assertNull($this->connection->query("SELECT to_regclass('schema_migrations')")->fetchColumn());
        self::assertFalse($this->connection->inTransaction());
    }

    /**
     * Verifies: rejects changes to applied migrations.
     *
     * @return void
     */
    public function testRejectsChangesToAppliedMigrations(): void
    {
        $migrationPath = $this->migrationDirectory . '/20261002000100_create.sql';
        file_put_contents($migrationPath, 'CREATE TABLE example (value INTEGER);');
        $runner = new MigrationRunner($this->connection);
        $runner->applyMigrations($this->migrationDirectory);
        file_put_contents($migrationPath, 'CREATE TABLE example (value TEXT);');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database migrations failed. No pending migrations were applied.');
        $runner->applyMigrations($this->migrationDirectory);
    }
}
