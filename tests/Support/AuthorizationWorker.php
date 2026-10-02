<?php

declare(strict_types=1);

namespace Ordinal\Tests\Support;

use InvalidArgumentException;
use Ordinal\Configuration\SecurityConfigurationLoader;
use Ordinal\Repository\AuthorizationRepository;
use Ordinal\Repository\ProviderConnectionRepository;
use Ordinal\Security\TokenCipher;
use Ordinal\Service\AuthorizationService;
use Ordinal\Service\ProviderRegistry;

/** Exercises authorization refresh on an independent PostgreSQL worker connection. */
final class AuthorizationWorker
{
    /**
     * Enters only an account-test schema and requests the current fixture token.
     *
     * @param string $schemaName
     * @param int $userId
     * @return string
     */
    public static function refresh(string $schemaName, int $userId): string
    {
        if (preg_match('/^account_test_[a-f0-9]{16}$/D', $schemaName) !== 1) {
            throw new InvalidArgumentException('Refresh workers require an isolated account-test schema.');
        }
        $connection = TestDatabase::createConnection();
        $connection->exec('SET search_path TO ' . $schemaName);
        $connection->prepare("SELECT set_config('application_name', :name, false)")->execute(['name' => $schemaName]);
        $configuration = SecurityConfigurationLoader::load(['encryptionKey' => str_repeat('ab', 32), 'bootstrapConnection' => 'primary', 'bootstrapUserId' => '8', 'connections' => ['primary' => ['kind' => 'gitlab', 'serverUrl' => 'https://gitlab.example', 'clientId' => 'app', 'clientSecret' => 'fake-secret', 'redirectUri' => 'https://ordinal.example/login/callback']]]);
        $registry = new ProviderRegistry(new ProviderConnectionRepository($connection), $configuration, new RefreshHttpClient($connection));
        return (new AuthorizationService($connection, new AuthorizationRepository($connection, new TokenCipher($configuration->encryptionKey)), $registry))->getAuthorization($userId)->accessToken;
    }
}
