<?php

declare(strict_types=1);

namespace Ordinal\Tests\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use Ordinal\Configuration\SecurityConfigurationLoader;
use Ordinal\Model\AllocationCaller;
use Ordinal\Model\BrowserSession;
use Ordinal\Service\AccountApplication;
use Ordinal\Service\AccountException;
use Ordinal\Tests\Unit\Configuration\SecurityConfigurationLoaderTest;

/** Runs reset/allocation against independent guarded connections for lock-overlap verification. */
final class ProjectAdministrationWorker
{
    /**
     * Allocates a new request or performs a confirmed reset after persisted session checks.
     *
     * @param string $schemaName
     * @param string $operation
     * @param int $userId
     * @param int $sessionId
     * @param string $csrfToken
     * @return string
     */
    public static function run(string $schemaName, string $operation, int $userId, int $sessionId, string $csrfToken): string
    {
        if (preg_match('/^phase7_test_[a-f0-9]{16}$/D', $schemaName) !== 1 || !in_array($operation, ['allocate', 'reset', 'edit'], true)) {
            throw new InvalidArgumentException('Workers require a known operation and isolated schema.');
        }
        $connection = TestDatabase::createConnection();
        $connection->exec('SET search_path TO ' . $schemaName);
        $connection->exec("SET lock_timeout TO '10s'");
        $statement = $connection->prepare("SELECT set_config('application_name', :name, false)");
        $statement->execute(['name' => $schemaName]);
        $app = new AccountApplication($connection, SecurityConfigurationLoader::load(SecurityConfigurationLoaderTest::createSettings()));
        if ($operation === 'allocate') {
            return (string) $app->buildNumbers->allocateBuildNumber(1, '22222222-2222-4222-8222-222222222222', new AllocationCaller(userId: $userId));
        }
        $session = new BrowserSession($sessionId, $userId, 1, '8', 'User 8', $csrfToken, null, new DateTimeImmutable('+1 hour'));
        try {
            if ($operation === 'edit') { $app->projectAdministration->editCounter($session, 1, 42); return 'EDIT'; }
            $app->projectAdministration->resetCounter($session, 1, 0, 'Project', true);
            return 'RESET';
        } catch (AccountException | \Ordinal\Security\AuthenticationException) { return 'DENIED'; }
    }
}
