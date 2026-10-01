<?php

declare(strict_types=1);

namespace Ordinal\Configuration;

use InvalidArgumentException;
use Ordinal\Model\DatabaseConfiguration;
use SensitiveParameter;

/**
 * Loads and validates PostgreSQL settings without disclosing configuration values.
 */
final class ConfigurationLoader
{
    /** Lists required database environment settings. */
    private const array DATABASE_SETTING_NAMES = [
        'ORDINAL_DATABASE_DSN',
        'ORDINAL_DATABASE_USER',
        'ORDINAL_DATABASE_PASSWORD',
    ];

    /**
     * Reads the process environment and validates the required database settings.
     *
     * @return DatabaseConfiguration
     * @throws \InvalidArgumentException
     */
    public static function loadFromEnvironment(): DatabaseConfiguration
    {
        $settings = [];

        foreach (self::DATABASE_SETTING_NAMES as $settingName) {
            $settings[$settingName] = getenv($settingName);
        }

        return self::load($settings);
    }

    /**
     * Validates raw settings and preserves credential values exactly as supplied.
     *
     * @param array $settings
     * @return DatabaseConfiguration
     * @throws \InvalidArgumentException
     */
    public static function load(
        #[SensitiveParameter]
        array $settings,
    ): DatabaseConfiguration {
        foreach (self::DATABASE_SETTING_NAMES as $settingName) {
            if (!isset($settings[$settingName]) || !is_string($settings[$settingName])
                || trim($settings[$settingName]) === '') {
                throw new InvalidArgumentException('Invalid configuration: ' . $settingName . '.');
            }
        }

        if (!str_starts_with($settings['ORDINAL_DATABASE_DSN'], 'pgsql:')) {
            throw new InvalidArgumentException('Invalid configuration: ORDINAL_DATABASE_DSN.');
        }

        return new DatabaseConfiguration(
            $settings['ORDINAL_DATABASE_DSN'],
            $settings['ORDINAL_DATABASE_USER'],
            $settings['ORDINAL_DATABASE_PASSWORD'],
        );
    }
}
