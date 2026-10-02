<?php

declare(strict_types=1);

namespace Ordinal\Configuration;

use InvalidArgumentException;

/** Loads one local administrator password hash, without provider registration or encryption keys. */
final class AdministratorConfigurationLoader
{
    /**
     * Loads an environment hash or a private configuration outside the document root.
     *
     * @return string
     */
    public static function loadPasswordHash(): string
    {
        $hash = getenv('ORDINAL_ADMIN_PASSWORD_HASH');
        if (!is_string($hash) || $hash === '') {
            $path = getenv('ORDINAL_ADMIN_CONFIG');
            $path = is_string($path) && $path !== '' ? $path : dirname(__DIR__, 2) . '/.ordinal/admin.php';
            $resolved = realpath($path);
            $public = realpath(dirname(__DIR__, 2) . '/public');
            if ($resolved === false || $public === false || !is_file($resolved)
                || str_starts_with(strtolower(str_replace('\\', '/', $resolved)), strtolower(str_replace('\\', '/', $public)) . '/')) {
                throw new InvalidArgumentException('Configure the administrator password first.');
            }
            ob_start();
            try {
                $settings = require $resolved;
                if (ob_get_contents() !== '' || !is_array($settings)) { throw new InvalidArgumentException('Invalid administrator configuration.'); }
                $hash = $settings['passwordHash'] ?? null;
            } finally { ob_end_clean(); }
        }
        if (!is_string($hash) || password_get_info($hash)['algo'] === null) {
            throw new InvalidArgumentException('Invalid administrator password hash.');
        }
        return $hash;
    }
}
