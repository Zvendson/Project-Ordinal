<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Configuration;

use InvalidArgumentException;
use Ordinal\Configuration\ConfigurationLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the behavior of ConfigurationLoader.
 */
final class ConfigurationLoaderTest extends TestCase
{
    /**
     * Verifies: loads database configuration.
     *
     * @return void
     */
    public function testLoadsDatabaseConfiguration(): void
    {
        $configuration = ConfigurationLoader::load([
            'ORDINAL_DATABASE_DSN'      => 'pgsql:host=127.0.0.1;dbname=ordinal_test',
            'ORDINAL_DATABASE_USER'     => 'ordinal_test',
            'ORDINAL_DATABASE_PASSWORD' => 'test-password',
        ]);

        self::assertSame('pgsql:host=127.0.0.1;dbname=ordinal_test', $configuration->dsn);
        self::assertSame('ordinal_test', $configuration->userName);
        self::assertSame('test-password', $configuration->password);
    }

    /**
     * Verifies: reports invalid settings without exposing their values.
     *
     * @param string $settingName
     * @param mixed $settingValue
     * @return void
     */
    #[DataProvider('provideInvalidSettings')]
    public function testReportsInvalidSettingsWithoutExposingTheirValues(string $settingName, mixed $settingValue): void
    {
        $settings = [
            'ORDINAL_DATABASE_DSN'      => 'pgsql:host=127.0.0.1;dbname=ordinal_test',
            'ORDINAL_DATABASE_USER'     => 'ordinal_test',
            'ORDINAL_DATABASE_PASSWORD' => 'test-password',
        ];
        $settings[$settingName] = $settingValue;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configuration: ' . $settingName . '.');
        ConfigurationLoader::load($settings);
    }

    /**
     * Supplies missing, empty, and unsupported database setting values.
     *
     * @return array
     */
    public static function provideInvalidSettings(): array
    {
        return [
            ['ORDINAL_DATABASE_DSN', false],
            ['ORDINAL_DATABASE_USER', ''],
            ['ORDINAL_DATABASE_PASSWORD', null],
            ['ORDINAL_DATABASE_DSN', 'mysql:password=private-value'],
        ];
    }
}
