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
     * @return ?array
     */
    public function findProject(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT p.*, c.disabled_at AS connection_disabled_at FROM projects p JOIN provider_connections c ON c.id = p.provider_connection_id WHERE p.id = :id');
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
}
