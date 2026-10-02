<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Ordinal\Database\Transaction;
use Ordinal\Model\AllocationCaller;
use Ordinal\Repository\AllocationRepository;
use Ordinal\Repository\TokenRepository;
use Ordinal\Security\AuthenticationException;
use PDO;

/** Allocates or replays numbers atomically and rechecks locked credential validity after lock waits. */
final class BuildNumberService
{
    /** Defines the inclusive uint32 maximum. */
    private const int MAX_BUILD_NUMBER = 4294967295;

    /**
     * Uses one connection for the project lock, allocation, counter, and audit.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Provides the database connection for atomic allocation. */
        private readonly PDO $connection,
    ) {}

    /**
     * Returns an existing retry number or saves one new allocation and successful audit.
     *
     * @param int $projectId
     * @param string $requestId
     * @param AllocationCaller $caller
     * @return int
     * @throws AllocationException
     * @throws AuthenticationException
     * @throws \Throwable
     */
    public function allocateBuildNumber(int $projectId, string $requestId, AllocationCaller $caller): int
    {
        $buildNumber = 0;
        $repository  = new AllocationRepository($this->connection);
        (new Transaction($this->connection))->execute(
            /**
             * Performs allocation/replay while holding the project row lock.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($projectId, $requestId, $caller, $repository, &$buildNumber): void {
                if ($caller->automationTokenId !== null) {
                    if ($caller->automationSecretHash === null) {
                        throw new AuthenticationException('Verified automation authentication is required.');
                    }
                    $automation = new TokenRepository($connection);
                    $automation->requireActiveToken($automation->findToken($caller->automationTokenId, true), $projectId, $caller->automationSecretHash);
                }
                $project = $repository->lockProject($projectId);
                if ($project === null || $project['archived_at'] !== null || $project['disabled_at'] !== null) {
                    throw new AllocationException(AllocationException::PROJECT_NOT_FOUND);
                }
                // Re-evaluate expiry after the project lock wait, while credential locks remain held.
                if ($caller->automationTokenId !== null) {
                    $automation->requireActiveToken($automation->findToken($caller->automationTokenId), $projectId, $caller->automationSecretHash);
                }
                if ($caller->getKind() === AllocationCaller::ANONYMOUS && $project['is_authentication_required']) {
                    throw new AllocationException(AllocationException::INVALID_AUTHENTICATION);
                }
                $allocation = $repository->findAllocation($projectId, $requestId, $caller);
                if ($allocation !== null) {
                    $buildNumber = $allocation['build_number'];
                    $repository->createAuditEvent($projectId, $allocation['id'], $caller, true);
                    return;
                }
                if ($project['is_exhausted']) {
                    throw new AllocationException(AllocationException::BUILD_COUNTER_EXHAUSTED);
                }
                $buildNumber  = $project['next_build_number'];
                $allocationId = $repository->createAllocation($projectId, $requestId, $buildNumber, $caller);
                $isExhausted  = $buildNumber === self::MAX_BUILD_NUMBER;
                $repository->updateCounter($projectId, $isExhausted ? $buildNumber : $buildNumber + 1, $isExhausted);
                $repository->createAuditEvent($projectId, $allocationId, $caller, false);
            },
        );
        return $buildNumber;
    }
}
