<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Database;

use Ordinal\Database\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies the behavior of MigrationRunner.
 */
final class MigrationRunnerTest extends TestCase
{
    /**
     * Verifies: rejects an unavailable directory before starting a transaction.
     *
     * @return void
     */
    public function testRejectsAnUnavailableDirectoryBeforeStartingATransaction(): void
    {
        $connection = $this->createMock(PDO::class);
        $connection->expects(self::never())->method('beginTransaction');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migration directory is unavailable.');
        (new MigrationRunner($connection))->applyMigrations(__DIR__ . '/missing-migrations');
    }
}
