<?php

declare(strict_types=1);

namespace Ordinal\Tests\Support;

use InvalidArgumentException;
use Ordinal\Model\AllocationCaller;
use Ordinal\Security\AuthenticationException;
use Ordinal\Service\BuildNumberService;

/** Runs previously verified project-token callers on independent guarded database connections. */
final class TokenAllocationWorker
{
    /**
     * Reports allocation or invalidation after waiting for the token lock.
     *
     * @param string $schemaName
     * @param int $tokenId
     * @param string $hash
     * @param string $requestId
     * @return string
     */
    public static function allocate(string $schemaName, int $tokenId, string $hash, string $requestId): string
    {
        if (preg_match('/^management_fixture_[a-f0-9]{16}$/D', $schemaName) !== 1) {
            throw new InvalidArgumentException('Workers require an isolated management test schema.');
        }
        $connection = TestDatabase::createConnection();
        $connection->exec('SET search_path TO ' . $schemaName);
        $connection->exec("SET lock_timeout TO '10s'");
        $statement = $connection->prepare("SELECT set_config('application_name', :name, false)");
        $statement->execute(['name' => $schemaName]);
        try {
            return (string) (new BuildNumberService($connection))->allocateBuildNumber(1, $requestId, new AllocationCaller(automationTokenId: $tokenId, automationSecretHash: $hash));
        } catch (AuthenticationException) {
            return 'INVALID_AUTHENTICATION';
        }
    }
}
