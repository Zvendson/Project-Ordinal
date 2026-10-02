<?php

declare(strict_types=1);

namespace Ordinal\Repository;

use Ordinal\Model\AllocationCaller;
use PDO;

/** Persists project counters, permanent retry records, and their successful audit events. */
final class AllocationRepository
{
    /**
     * Uses the same connection as the service's transaction.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Provides the transaction-bound database connection. */
        private readonly PDO $connection,
    ) {}

    /**
     * Locks the project so allocation, replay, and later counter edits serialize.
     *
     * @param int $projectId
     * @return ?array
     */
    public function lockProject(int $projectId): ?array
    {
        $statement = $this->connection->prepare(<<<'SQL'
            SELECT project.*, connection.disabled_at,
                COALESCE(project.authentication_required_override, settings.is_authentication_required) AS is_authentication_required
            FROM projects AS project
            JOIN provider_connections AS connection ON connection.id = project.provider_connection_id
            CROSS JOIN instance_settings AS settings
            WHERE project.id = :projectId AND settings.id = 1
            FOR UPDATE OF project
            SQL);
        $statement->execute(['projectId' => $projectId]);
        $project = $statement->fetch();
        return $project === false ? null : $project;
    }

    /**
     * Finds the original allocation in the verified caller's permanent retry namespace.
     *
     * @param int $projectId
     * @param string $requestId
     * @param AllocationCaller $caller
     * @return ?array
     */
    public function findAllocation(int $projectId, string $requestId, AllocationCaller $caller): ?array
    {
        $statement = $this->connection->prepare(<<<'SQL'
            SELECT id, build_number FROM allocations
            WHERE project_id = :projectId AND request_id = :requestId AND caller_kind = :kind
                AND user_id IS NOT DISTINCT FROM CAST(:userId AS BIGINT)
                AND automation_token_id IS NOT DISTINCT FROM CAST(:tokenId AS BIGINT)
            SQL);
        $statement->execute(['projectId' => $projectId, 'requestId' => $requestId, 'kind' => $caller->getKind(), 'userId' => $caller->userId, 'tokenId' => $caller->automationTokenId]);
        $allocation = $statement->fetch();
        return $allocation === false ? null : $allocation;
    }

    /**
     * Saves one permanent allocation and returns its stable record ID.
     *
     * @param int $projectId
     * @param string $requestId
     * @param int $buildNumber
     * @param AllocationCaller $caller
     * @return int
     */
    public function createAllocation(int $projectId, string $requestId, int $buildNumber, AllocationCaller $caller): int
    {
        $statement = $this->connection->prepare(<<<'SQL'
            INSERT INTO allocations
                (project_id, request_id, build_number, caller_kind, user_id, automation_token_id, device_credential_id)
            VALUES (:projectId, :requestId, :number, :kind, :userId, :tokenId, :credentialId)
            RETURNING id
            SQL);
        $statement->execute(['projectId' => $projectId, 'requestId' => $requestId, 'number' => $buildNumber, 'kind' => $caller->getKind(), 'userId' => $caller->userId, 'tokenId' => $caller->automationTokenId, 'credentialId' => $caller->deviceCredentialId]);
        return (int) $statement->fetchColumn();
    }

    /**
     * Advances a locked counter or retains its maximum with explicit exhaustion.
     *
     * @param int $projectId
     * @param int $nextBuildNumber
     * @param bool $isExhausted
     * @return void
     */
    public function updateCounter(int $projectId, int $nextBuildNumber, bool $isExhausted): void
    {
        $statement = $this->connection->prepare(<<<'SQL'
            UPDATE projects
            SET next_build_number = :number, has_allocated_build_number = TRUE, is_exhausted = :isExhausted
            WHERE id = :projectId
            SQL);
        $statement->bindValue('number', $nextBuildNumber, PDO::PARAM_INT);
        $statement->bindValue('isExhausted', $isExhausted, PDO::PARAM_BOOL);
        $statement->bindValue('projectId', $projectId, PDO::PARAM_INT);
        $statement->execute();
    }

    /**
     * Saves a successful allocation/replay audit event inside the same transaction.
     *
     * @param int $projectId
     * @param int $allocationId
     * @param AllocationCaller $caller
     * @param bool $isReplay
     * @return void
     */
    public function createAuditEvent(int $projectId, int $allocationId, AllocationCaller $caller, bool $isReplay): void
    {
        $statement = $this->connection->prepare(<<<'SQL'
            INSERT INTO audit_events
                (project_id, allocation_id, user_id, automation_token_id, device_credential_id, action, outcome)
            VALUES (:projectId, :allocationId, :userId, :tokenId, :credentialId, 'allocate_build_number', :outcome)
            SQL);
        $statement->execute(['projectId' => $projectId, 'allocationId' => $allocationId, 'userId' => $caller->userId, 'tokenId' => $caller->automationTokenId, 'credentialId' => $caller->deviceCredentialId, 'outcome' => $isReplay ? 'replayed' : 'allocated']);
    }
}
