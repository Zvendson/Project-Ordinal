<?php

declare(strict_types=1);

namespace Ordinal\Repository;

use PDO;

/** Persists administrator grants and serializes changes through the singleton settings row. */
final readonly class AdministrationRepository
{
    /**
     * Uses stable user IDs for grants and audit actors.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Stores instance policy, grants, and initial phase-four audit events. */
        private PDO $connection,
    ) {}

    /**
     * Locks instance policy to serialize grant removals and configuration changes.
     *
     * @return void
     */
    public function lockSettings(): void
    {
        $this->connection->query('SELECT id FROM instance_settings WHERE id = 1 FOR UPDATE')->fetchColumn();
    }

    /**
     * Checks the current local instance-administrator grant.
     *
     * @param int $userId
     * @return bool
     */
    public function isInstanceAdministrator(int $userId): bool
    {
        $statement = $this->connection->prepare('SELECT EXISTS (SELECT 1 FROM instance_administrators WHERE user_id = :id AND revoked_at IS NULL)');
        $statement->execute(['id' => $userId]);
        return (bool) $statement->fetchColumn();
    }

    /**
     * Grants or restores administration while preserving its stable record.
     *
     * @param int $userId
     * @param ?int $actorId
     * @return void
     */
    public function grantAdministrator(int $userId, ?int $actorId): void
    {
        $statement = $this->connection->prepare('INSERT INTO instance_administrators (user_id, granted_by_user_id) VALUES (:user, :actor) ON CONFLICT (user_id) DO UPDATE SET revoked_at = NULL, granted_by_user_id = EXCLUDED.granted_by_user_id');
        $statement->execute(['user' => $userId, 'actor' => $actorId]);
    }

    /**
     * Counts currently active grants while the caller holds the settings mutex.
     *
     * @return int
     */
    public function countAdministrators(): int
    {
        return (int) $this->connection->query('SELECT count(*) FROM instance_administrators WHERE revoked_at IS NULL')->fetchColumn();
    }

    /**
     * Revokes rather than deletes a grant so references and identity history remain intact.
     *
     * @param int $userId
     * @return void
     */
    public function revokeAdministrator(int $userId): void
    {
        $statement = $this->connection->prepare('UPDATE instance_administrators SET revoked_at = clock_timestamp() WHERE user_id = :id AND revoked_at IS NULL');
        $statement->execute(['id' => $userId]);
    }

    /**
     * Reads current policy for protected configuration forms.
     *
     * @return array
     */
    public function getSettings(): array
    {
        return $this->connection->query('SELECT * FROM instance_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Changes policy used only when future browser sessions are created.
     *
     * @param int $idleMinutes
     * @param int $absoluteMinutes
     * @return void
     */
    public function saveSessionLimits(int $idleMinutes, int $absoluteMinutes): void
    {
        $statement = $this->connection->prepare('UPDATE instance_settings SET browser_idle_minutes = :idle, browser_absolute_minutes = :absolute WHERE id = 1');
        $statement->execute(['idle' => $idleMinutes, 'absolute' => $absoluteMinutes]);
    }

    /**
     * Records a fixed action name and actor without accepting free-form secrets.
     *
     * @param int $actorId
     * @param string $action
     * @param ?int $projectId
     * @return void
     */
    public function recordEvent(int $actorId, string $action, ?int $projectId = null): void
    {
        $statement = $this->connection->prepare("INSERT INTO audit_events (user_id, project_id, action, outcome) VALUES (:user, :project, :action, 'success')");
        $statement->execute(['user' => $actorId, 'project' => $projectId, 'action' => $action]);
    }
}
