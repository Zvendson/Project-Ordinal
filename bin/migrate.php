<?php

/**
 * Applies pending database migrations using process environment configuration.
 */

declare(strict_types=1);

use Ordinal\Configuration\ConfigurationLoader;
use Ordinal\Database\ConnectionFactory;
use Ordinal\Database\MigrationRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $configuration = ConfigurationLoader::loadFromEnvironment();
    $connection    = ConnectionFactory::createConnection($configuration);
    $appliedCount  = (new MigrationRunner($connection))->applyMigrations(dirname(__DIR__) . '/database/migrations');
    fwrite(STDOUT, 'Applied migrations: ' . $appliedCount . PHP_EOL);
} catch (InvalidArgumentException | RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
