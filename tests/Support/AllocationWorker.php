<?php

declare(strict_types=1);

namespace Ordinal\Tests\Support;

use InvalidArgumentException;
use Ordinal\Model\AllocationCaller;
use Ordinal\Service\BuildNumberService;

/** Runs an allocation on an independent PostgreSQL connection for concurrency tests. */
final class AllocationWorker
{
    /**
     * Enters only the test-created schema and performs one trusted anonymous allocation.
     *
     * @param string $schemaName
     * @param string $requestId
     * @return int
     * @throws \Throwable
     */
    public static function allocate(string $schemaName, string $requestId): int
    {
        if (preg_match('/^allocation_test_[a-f0-9]{16}$/D', $schemaName) !== 1) {
            throw new InvalidArgumentException('Concurrency workers require an isolated test schema.');
        }
        $connection = TestDatabase::createConnection();
        $connection->exec('SET search_path TO ' . $schemaName);
        $statement = $connection->prepare("SELECT set_config('application_name', :name, false)");
        $statement->execute(['name' => $schemaName]);
        return (new BuildNumberService($connection))->allocateBuildNumber(1, $requestId, new AllocationCaller());
    }
}
