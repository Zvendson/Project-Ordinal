<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the behavior of Runtime.
 */
final class RuntimeTest extends TestCase
{
    /**
     * Verifies: supports unsigned build numbers.
     *
     * @return void
     */
    public function testSupportsUnsignedBuildNumbers(): void
    {
        self::assertSame(8, PHP_INT_SIZE);
        self::assertGreaterThanOrEqual(4294967295, PHP_INT_MAX);
    }

    /**
     * Verifies: provides required extensions.
     *
     * @return void
     */
    public function testProvidesRequiredExtensions(): void
    {
        foreach (['pdo_pgsql', 'openssl', 'sodium', 'mbstring', 'dom', 'xml', 'xmlwriter'] as $extensionName) {
            self::assertTrue(extension_loaded($extensionName), 'Missing PHP extension: ' . $extensionName);
        }

        self::assertContains('pgsql', PDO::getAvailableDrivers());
    }
}
