<?php

declare(strict_types=1);

namespace Ordinal\Repository;

use Ordinal\Model\AllocationCaller;
use PDO;

/** Stores structured secret-free events and reads deletable history independently of permanent retries. */
final readonly class AuditRepository
{
    /** Bounds each history/log page. */
    public const int PAGE_SIZE = 100;

    /**
     * Uses the owning transaction for successful administrative events.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Reads audit data without selecting credential columns. */
        private PDO $connection,
    ) {}

    /**
     * Records trusted administrative fields in the mutation's transaction.
     *
     * @param int $actorId
     * @param ?int $projectId
     * @param string $action
     * @param array $details
     * @return void
     */
    public function recordEvent(int $actorId, ?int $projectId, string $action, array $details = []): void
    {
        $statement = $this->connection->prepare("INSERT INTO audit_events (user_id, project_id, action, outcome, details) VALUES (:user, :project, :action, 'success', CAST(:details AS JSONB))");
        $statement->execute(['user' => $actorId, 'project' => $projectId, 'action' => $action, 'details' => json_encode((object) $details, JSON_THROW_ON_ERROR)]);
    }

    /**
     * Records an unsuccessful allocation attempt using only verified identity and validated metadata.
     *
     * @param ?int $projectId
     * @param ?string $requestId
     * @param ?AllocationCaller $caller
     * @param string $errorCode
     * @param ?string $reason
     * @return void
     */
    public function recordAllocationFailure(
        ?int              $projectId,
        ?string           $requestId,
        ?AllocationCaller $caller,
        string            $errorCode,
        ?string           $reason    = null,
    ): void
    {
        $statement = $this->connection->prepare("INSERT INTO audit_events (project_id, user_id, automation_token_id, device_credential_id, action, outcome, details)
            VALUES ((SELECT id FROM projects WHERE id = :project), :user, :token, :device, 'allocate_build_number', :outcome, CAST(:details AS JSONB))");
        $details = ['requestId' => $requestId, 'callerKind' => $caller?->getKind() ?? 'unverified'];
        if (in_array($reason, ['CREDENTIAL_EXPIRED', 'CREDENTIAL_REVOKED'], true)) { $details['reason'] = $reason; }
        $statement->execute(['project' => $projectId, 'user' => $caller?->userId, 'token' => $caller?->automationTokenId, 'device' => $caller?->deviceCredentialId,
            'outcome' => $errorCode, 'details' => json_encode((object) $details, JSON_THROW_ON_ERROR)]);
    }

    /**
     * Reads bounded pages with provider-qualified users or named CI attribution, never secrets.
     *
     * @param ?int $projectId
     * @param ?int $userId
     * @param bool $isBuildHistory
     * @param ?int $beforeId
     * @return array
     */
    public function findEvents(?int $projectId, ?int $userId, bool $isBuildHistory, ?int $beforeId = null): array
    {
        $filters = [];
        $parameters = [];
        foreach (['e.project_id' => $projectId, 'e.user_id' => $userId] as $column => $value) {
            if ($value !== null) {
                $name = $column === 'e.project_id' ? 'project' : 'user';
                $filters[] = $column === 'e.user_id' && $isBuildHistory ? '(e.user_id = :user OR p.is_other_history_visible)' : $column . ' = :' . $name;
                $parameters[$name] = $value;
            }
        }
        if ($isBuildHistory) { $filters[] = "e.action = 'allocate_build_number' AND e.allocation_id IS NOT NULL"; }
        if ($beforeId !== null) { $filters[] = 'e.id < :before'; $parameters['before'] = $beforeId; }
        $statement = $this->connection->prepare("SELECT e.id, e.project_id, e.allocation_id, e.user_id, e.automation_token_id, e.action, e.outcome, e.details,
            to_char(e.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS created_at_utc,
            a.build_number, a.request_id, COALESCE(a.caller_kind, e.details ->> 'callerKind') AS caller_kind,
            u.display_name, u.provider_user_id, u.provider_connection_id, c.name AS provider_name,
            c.server_url AS provider_server_url, t.name AS automation_name, p.name AS project_name
            FROM audit_events e LEFT JOIN allocations a ON a.id = e.allocation_id LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN provider_connections c ON c.id = u.provider_connection_id LEFT JOIN automation_tokens t ON t.id = e.automation_token_id
            LEFT JOIN projects p ON p.id = e.project_id" . ($filters === [] ? '' : ' WHERE ' . implode(' AND ', $filters)) . ' ORDER BY e.id DESC LIMIT ' . self::PAGE_SIZE);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Counts only deletable audit entries older than a validated UTC cutoff.
     *
     * @param string $cutoff
     * @return int
     */
    public function countBefore(string $cutoff): int
    {
        $statement = $this->connection->prepare('SELECT count(*) FROM audit_events WHERE created_at < CAST(:cutoff AS TIMESTAMPTZ)');
        $statement->execute(['cutoff' => $cutoff]);
        return (int) $statement->fetchColumn();
    }

    /**
     * Deletes only audit rows, preserving every permanent identity and retry record.
     *
     * @param string $cutoff
     * @return int
     */
    public function deleteBefore(string $cutoff): int
    {
        $statement = $this->connection->prepare('DELETE FROM audit_events WHERE created_at < CAST(:cutoff AS TIMESTAMPTZ)');
        $statement->execute(['cutoff' => $cutoff]);
        return $statement->rowCount();
    }
}
