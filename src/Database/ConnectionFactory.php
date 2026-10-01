<?php

declare(strict_types=1);

namespace Ordinal\Database;

use Ordinal\Model\DatabaseConfiguration;
use PDO;
use PDOException;
use RuntimeException;
use SensitiveParameter;

/**
 * Creates PostgreSQL connections with consistent error, fetch, and timezone settings.
 */
final class ConnectionFactory
{
    /**
     * Opens a configured database connection.
     *
     * @param DatabaseConfiguration $configuration
     * @return PDO
     * @throws \RuntimeException
     */
    public static function createConnection(
        #[SensitiveParameter]
        DatabaseConfiguration $configuration,
    ): PDO {
        try {
            $connection = new PDO($configuration->dsn, $configuration->userName, $configuration->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES    => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $connection->exec("SET TIME ZONE 'UTC'");

            return $connection;
        } catch (PDOException) {
            throw new RuntimeException('Database connection failed. Check database configuration.');
        }
    }
}
