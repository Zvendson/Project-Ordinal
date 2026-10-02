<?php

declare(strict_types=1);

namespace Ordinal\Repository;

use Ordinal\Model\ProviderRepository;
use PDO;

/** Links projects to immutable provider-qualified repositories without local role lists. */
final readonly class ProjectRepository
{
    /**
     * Uses application persistence for linked repositories.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Stores project records and reads active connection metadata. */
        private PDO $connection,
    ) {}

    /**
     * Creates a link once; duplicates return null rather than changing an existing project.
     *
     * @param ProviderRepository $repository
     * @param string $name
     * @return ?int
     */
    public function createProject(ProviderRepository $repository, string $name): ?int
    {
        $statement = $this->connection->prepare('INSERT INTO projects (provider_connection_id, provider_repository_id, name) VALUES (:connection, :repository, :name) ON CONFLICT (provider_connection_id, provider_repository_id) DO NOTHING RETURNING id');
        $statement->execute(['connection' => $repository->providerConnectionId, 'repository' => $repository->providerRepositoryId, 'name' => $name]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Finds stable project identity and current archive/connection state.
     *
     * @param int $id
     * @param bool $isLocked
     * @return ?array
     */
    public function findProject(int $id, bool $isLocked = false): ?array
    {
        $statement = $this->connection->prepare('SELECT p.*, c.disabled_at AS connection_disabled_at,
            COALESCE(p.authentication_required_override, s.is_authentication_required) AS is_authentication_required,
            COALESCE(p.device_lifetime_days_override, s.device_lifetime_days) AS device_lifetime_days
            FROM projects p JOIN provider_connections c ON c.id = p.provider_connection_id CROSS JOIN instance_settings s WHERE p.id = :id AND s.id = 1' . ($isLocked ? ' FOR SHARE OF p' : ''));
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Lists candidate project records; permission filtering belongs to the service.
     *
     * @return array
     */
    public function findProjects(): array
    {
        return $this->connection->query('SELECT p.*, c.disabled_at AS connection_disabled_at FROM projects p JOIN provider_connections c ON c.id = p.provider_connection_id ORDER BY p.id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Changes a locked counter without clearing the permanent ever-allocated flag.
     *
     * @param int $id
     * @param int $number
     * @return void
     */
    public function saveCounter(int $id, int $number): void
    {
        $statement = $this->connection->prepare('UPDATE projects SET next_build_number = :number, is_exhausted = FALSE WHERE id = :id');
        $statement->execute(['number' => $number, 'id' => $id]);
    }

    /**
     * Changes only contributor visibility; recording continues regardless.
     *
     * @param int $id
     * @param bool $isVisible
     * @return void
     */
    public function saveHistoryVisibility(int $id, bool $isVisible): void
    {
        $statement = $this->connection->prepare('UPDATE projects SET is_other_history_visible = :visible WHERE id = :id');
        $statement->bindValue('visible', $isVisible, PDO::PARAM_BOOL);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /**
     * Archives/reactivates a stable project instead of deleting referenced records.
     *
     * @param int $id
     * @param bool $isArchived
     * @return void
     */
    public function saveArchiveState(int $id, bool $isArchived): void
    {
        $statement = $this->connection->prepare('UPDATE projects SET archived_at = CASE WHEN :archived THEN clock_timestamp() ELSE NULL END WHERE id = :id');
        $statement->bindValue('archived', $isArchived, PDO::PARAM_BOOL);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }
}
