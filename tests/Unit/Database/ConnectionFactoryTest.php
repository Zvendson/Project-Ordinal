<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Database;

use Ordinal\Database\ConnectionFactory;
use Ordinal\Model\DatabaseConfiguration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies the behavior of ConnectionFactory.
 */
final class ConnectionFactoryTest extends TestCase
{
    /**
     * Verifies: does not expose credentials when connection fails.
     *
     * @return void
     */
    public function testDoesNotExposeCredentialsWhenConnectionFails(): void
    {
        $configuration = new DatabaseConfiguration('missing-driver:', 'private-user', 'private-password');

        try {
            ConnectionFactory::createConnection($configuration);
            self::fail('Expected a connection failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Database connection failed. Check database configuration.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
}
