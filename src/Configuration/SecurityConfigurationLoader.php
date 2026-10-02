<?php

declare(strict_types=1);

namespace Ordinal\Configuration;

use InvalidArgumentException;
use Ordinal\Model\ProviderConfiguration;
use Ordinal\Model\SecurityConfiguration;
use SensitiveParameter;

/** Loads operator-managed secrets and the fixed bootstrap identity from a private PHP file. */
final class SecurityConfigurationLoader
{
    /**
     * Loads a trusted file selected by the operator, never by HTTP input.
     *
     * @return SecurityConfiguration
     * @throws InvalidArgumentException
     */
    public static function loadFromEnvironment(): SecurityConfiguration
    {
        $path = getenv('ORDINAL_SECURITY_CONFIG');
        $resolved = is_string($path) ? realpath($path) : false;
        $publicPath = realpath(dirname(__DIR__, 2) . '/public');
        if ($resolved === false || !is_file($resolved) || $publicPath === false
            || str_starts_with(strtolower(str_replace('\\', '/', $resolved)), strtolower(str_replace('\\', '/', $publicPath)) . '/')) {
            throw new InvalidArgumentException('Invalid configuration: ORDINAL_SECURITY_CONFIG.');
        }
        ob_start();
        try {
            $settings = require $resolved;
            if (ob_get_contents() !== '' || !is_array($settings)) {
                throw new InvalidArgumentException('Invalid security configuration.');
            }
        } finally {
            ob_end_clean();
        }
        return self::load($settings);
    }

    /**
     * Validates trusted registrations without exposing any configuration values.
     *
     * @param array $settings
     * @return SecurityConfiguration
     * @throws InvalidArgumentException
     */
    public static function load(#[SensitiveParameter] array $settings): SecurityConfiguration
    {
        if (!is_string($settings['encryptionKey'] ?? null) || preg_match('/^[a-fA-F0-9]{64}$/D', $settings['encryptionKey']) !== 1
            || !is_string($settings['bootstrapConnection'] ?? null) || !is_string($settings['bootstrapUserId'] ?? null)
            || preg_match('/^[1-9][0-9]*$/D', $settings['bootstrapUserId']) !== 1
            || !is_array($settings['connections'] ?? null) || $settings['connections'] === []) {
            throw new InvalidArgumentException('Invalid security configuration.');
        }
        $connections = [];
        foreach ($settings['connections'] as $reference => $registration) {
            if (!is_string($reference) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $reference) !== 1 || !is_array($registration)
                || !in_array($registration['kind'] ?? null, ['github', 'gitlab'], true)) {
                throw new InvalidArgumentException('Invalid provider registration.');
            }
            foreach (['serverUrl', 'clientId', 'clientSecret', 'redirectUri'] as $key) {
                if (!is_string($registration[$key] ?? null)) {
                    throw new InvalidArgumentException('Invalid provider registration.');
                }
            }
            ProviderConfiguration::assertSecureUrl($registration['serverUrl']);
            if (parse_url($registration['serverUrl'], PHP_URL_QUERY) !== null
                || ($registration['kind'] === 'github' && rtrim($registration['serverUrl'], '/') !== 'https://github.com')) {
                throw new InvalidArgumentException('Invalid provider server URL.');
            }
            $connections[$reference] = [
                'kind' => $registration['kind'], 'serverUrl' => rtrim($registration['serverUrl'], '/'),
                'client' => new ProviderConfiguration($registration['clientId'], $registration['clientSecret'], $registration['redirectUri']),
            ];
        }
        if (!isset($connections[$settings['bootstrapConnection']])) {
            throw new InvalidArgumentException('Bootstrap provider registration must exist.');
        }
        return new SecurityConfiguration($settings['encryptionKey'], $settings['bootstrapConnection'], $settings['bootstrapUserId'], $connections);
    }
}
