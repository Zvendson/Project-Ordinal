<?php

declare(strict_types=1);

namespace Ordinal\Tests\Support;

use InvalidArgumentException;
use Ordinal\Model\AllocationCaller;
use Ordinal\Security\AuthenticationException;
use Ordinal\Service\BuildNumberService;

/** Runs trusted device callers on independent connections to exercise transaction locking. */
final class DeviceAllocationWorker
{
    /**
     * Enters only a guarded random schema and reports allocation or single-use denial.
     *
     * @param string $schemaName
     * @param int $credentialId
     * @param int $userId
     * @param string $requestId
     * @return string
     */
    public static function allocate(string $schemaName, int $credentialId, int $userId, string $requestId): string
    {
        if (preg_match('/^device_test_[a-f0-9]{16}$/D', $schemaName) !== 1) {
            throw new InvalidArgumentException('Concurrency workers require an isolated device test schema.');
        }
        $connection = TestDatabase::createConnection();
        $connection->exec('SET search_path TO ' . $schemaName);
        $connection->exec("SET lock_timeout TO '10s'");
        $statement = $connection->prepare("SELECT set_config('application_name', :name, false)");
        $statement->execute(['name' => $schemaName]);
        try {
            return (string) (new BuildNumberService($connection))->allocateBuildNumber(1, $requestId, new AllocationCaller($userId, deviceCredentialId: $credentialId));
        } catch (AuthenticationException) {
            return 'INVALID_AUTHENTICATION';
        }
    }
}
