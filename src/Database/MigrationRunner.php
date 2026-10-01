<?php

declare(strict_types=1);

namespace Ordinal\Database;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Applies ordered SQL migrations atomically and records their checksums.
 */
final class MigrationRunner
{
    /** Serializes migration batches within one PostgreSQL database. */
    private const int    MIGRATION_LOCK_ID      = 1897713436;
    /** Requires an ordering timestamp followed by a snake_case SQL filename. */
    private const string MIGRATION_FILE_PATTERN = '/^\d{14}_[a-z][a-z0-9_]*\.sql$/';

    /**
     * Uses the supplied PDO connection to apply and track migrations.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Provides the database connection used by these operations. */
        private readonly PDO $connection,
    ) {}

    /**
     * Applies pending migrations under a database lock and returns their count.
     *
     * @param string $migrationDirectory
     * @return int
     * @throws \RuntimeException
     */
    public function applyMigrations(string $migrationDirectory): int
    {
        if (!is_dir($migrationDirectory)) {
            throw new RuntimeException('Migration directory is unavailable.');
        }

        $migrationPaths = glob($migrationDirectory . '/*.sql');

        if ($migrationPaths === false) {
            throw new RuntimeException('Migration directory could not be read.');
        }

        sort($migrationPaths, SORT_STRING);
        $appliedCount = 0;

        try {
            (new Transaction($this->connection))->execute(
                /**
                 * Runs the database operation within the transaction.
                 *
                 * @param PDO $connection
                 * @return void
                 */
                function (PDO $connection) use ($migrationPaths, &$appliedCount): void {
                    $lock = $connection->prepare('SELECT pg_advisory_xact_lock(:lockId)');
                    $lock->execute(['lockId' => self::MIGRATION_LOCK_ID]);
                    $connection->exec($this->readSql(dirname(__DIR__, 2) . '/database/migration-history.sql'));
                    $findMigration = $connection->prepare('SELECT checksum FROM schema_migrations WHERE version = :version');
                    $saveMigration = $connection->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (:version, :checksum)');

                    foreach ($migrationPaths as $migrationPath) {
                        $version = basename($migrationPath);

                        if (preg_match(self::MIGRATION_FILE_PATTERN, $version) !== 1) {
                            throw new RuntimeException('Invalid migration filename.');
                        }

                        $sql      = $this->readSql($migrationPath);
                        $checksum = hash('sha256', $sql);
                        $findMigration->execute(['version' => $version]);
                        $savedChecksum = $findMigration->fetchColumn();

                        if ($savedChecksum !== false) {
                            if ($savedChecksum !== $checksum) {
                                throw new RuntimeException('An applied migration was changed.');
                            }

                            continue;
                        }

                        $connection->exec($sql);
                        $saveMigration->execute(['version' => $version, 'checksum' => $checksum]);
                        $appliedCount++;
                    }
                }
            );
        } catch (Throwable) {
            throw new RuntimeException('Database migrations failed. No pending migrations were applied.');
        }

        return $appliedCount;
    }

    /**
     * Reads a nonempty SQL file for execution inside the migration transaction.
     *
     * @param string $filePath
     * @return string
     * @throws \RuntimeException
     */
    private function readSql(string $filePath): string
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException('Migration SQL is unavailable.');
        }

        $sql = file_get_contents($filePath);

        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Migration SQL could not be read or is empty.');
        }

        return $sql;
    }
}
