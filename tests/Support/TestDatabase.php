<?php

declare(strict_types=1);

namespace Ordinal\Tests\Support;

use Ordinal\Configuration\ConfigurationLoader;
use Ordinal\Database\ConnectionFactory;
use PDO;
use RuntimeException;

/**
 * Restricts integration connections to the dedicated non-superuser test database.
 */
final class TestDatabase
{
    /**
     * Opens a configured database connection.
     *
     * @return PDO
     * @throws \RuntimeException
     */
    public static function createConnection(): PDO
    {
        $configuration = ConfigurationLoader::load([
            'ORDINAL_DATABASE_DSN'      => getenv('ORDINAL_TEST_DATABASE_DSN'),
            'ORDINAL_DATABASE_USER'     => 'ordinal_test',
            'ORDINAL_DATABASE_PASSWORD' => getenv('ORDINAL_TEST_DATABASE_PASSWORD'),
        ]);
        $connection = ConnectionFactory::createConnection($configuration);

        if ($connection->query('SELECT current_database()')->fetchColumn() !== 'ordinal_test'
            || $connection->query('SELECT current_user')->fetchColumn() !== 'ordinal_test'
            || $connection->query('SELECT rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn() !== false) {
            throw new RuntimeException('Integration tests require the isolated ordinal_test database and non-superuser login.');
        }

        return $connection;
    }
}
