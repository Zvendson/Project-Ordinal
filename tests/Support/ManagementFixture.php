<?php

declare(strict_types=1);

namespace Ordinal\Tests\Support;

use Ordinal\Database\MigrationRunner;
use Ordinal\Service\ManagementApplication;
use PDO;

/** Supplies isolated provider-free browser/API integration data. */
final class ManagementFixture
{
    /** Holds the guarded database connection. */
    public ?PDO                   $connection;
    /** Composes local services only. */
    public ?ManagementApplication $application;
    /** Identifies the isolated schema. */
    public string                 $schemaName;
    /** Supplies a test-only password. */
    public const string PASSWORD = 'test-only-management-password';

    /** Migrates an isolated schema without any provider credentials. */
    public function __construct()
    {
        $this->connection = TestDatabase::createConnection();
        $this->schemaName = 'management_fixture_' . bin2hex(random_bytes(8));
        $this->connection->exec('CREATE SCHEMA ' . $this->schemaName);
        $this->connection->exec('SET search_path TO ' . $this->schemaName);
        (new MigrationRunner($this->connection))->applyMigrations(dirname(__DIR__, 2) . '/database/migrations');
        $this->application = new ManagementApplication($this->connection, password_hash(self::PASSWORD, PASSWORD_DEFAULT));
    }

    /**
     * Creates a genuinely password-authenticated local administrator cookie.
     *
     * @return string
     */
    public function signIn(): string
    {
        $initial = $this->application->administrator->startSession();
        return $this->application->administrator->signIn($initial['cookie'], self::PASSWORD, $initial['session']->csrfToken, '127.0.0.1');
    }

    /**
     * Drops only the guarded schema and releases all database handles.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->connection === null) { return; }
        try {
            if ($this->connection->inTransaction()) { $this->connection->rollBack(); }
            $this->connection->exec('DROP SCHEMA ' . $this->schemaName . ' CASCADE');
        } finally { $this->application = null; $this->connection = null; }
    }
}
