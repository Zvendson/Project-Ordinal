<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use PDO;
use Ordinal\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the behavior of DatabaseConnection.
 */
final class DatabaseConnectionTest extends TestCase
{
    /** Names the dedicated database required by integration tests. */
    private const string TEST_DATABASE_NAME = 'ordinal_test';
    /** Names the non-superuser login required by integration tests. */
    private const string TEST_DATABASE_USER = 'ordinal_test';

    /**
     * Verifies: connects to isolated postgre sql database.
     *
     * @return void
     */
    public function testConnectsToIsolatedPostgreSqlDatabase(): void
    {
        $connection = TestDatabase::createConnection();

        self::assertSame('pgsql', $connection->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertSame(self::TEST_DATABASE_NAME, $connection->query('SELECT current_database()')->fetchColumn());
        self::assertSame(self::TEST_DATABASE_USER, $connection->query('SELECT current_user')->fetchColumn());
        self::assertSame(1, $connection->query('SELECT 1')->fetchColumn());
        self::assertFalse($connection->query('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn());
        self::assertSame(PDO::ERRMODE_EXCEPTION, $connection->getAttribute(PDO::ATTR_ERRMODE));
        self::assertFalse($connection->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        self::assertSame(PDO::FETCH_ASSOC, $connection->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        self::assertSame('UTC', $connection->query('SHOW TIME ZONE')->fetchColumn());
    }
}
