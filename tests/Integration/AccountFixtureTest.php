<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Tests\Support\AccountFixture;
use Ordinal\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Prevents closed fixtures from exhausting database connections as the acceptance suite grows. */
final class AccountFixtureTest extends TestCase
{
    /** Closing a fixture releases its database resources while the test still holds the fixture. @return void */
    public function testReleasesDatabaseConnectionWhenClosed(): void
    {
        $observer = TestDatabase::createConnection();
        $fixture = new AccountFixture();
        $backendId = (int) $fixture->connection->query('SELECT pg_backend_pid()')->fetchColumn();
        $fixture->close();
        $statement = $observer->prepare('SELECT count(*) FROM pg_stat_activity WHERE pid = :id');
        $deadline = microtime(true) + 1;
        do {
            $observer->query('SELECT pg_stat_clear_snapshot()');
            $statement->execute(['id' => $backendId]);
            $count = (int) $statement->fetchColumn();
            if ($count === 0) { break; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::assertSame(0, $count);
    }
}
