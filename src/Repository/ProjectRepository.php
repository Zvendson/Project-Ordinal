<?php

declare(strict_types=1);

namespace Ordinal\Repository;

use PDO;

/** Stores independent projects; repository URLs are optional descriptive links. */
final readonly class ProjectRepository
{
    /**
     * Uses the application's transaction connection.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Persists projects and counters. */
        private PDO $connection,
    ) {}

    /**
     * Creates a project without a provider account or repository identity.
     *
     * @param string $name
     * @param ?string $repositoryUrl
     * @return int
     */
    public function createProject(string $name, ?string $repositoryUrl): int
    {
        $statement = $this->connection->prepare('INSERT INTO projects (name, repository_url) VALUES (:name, :url) RETURNING id');
        $statement->execute(['name' => $name, 'url' => $repositoryUrl]);
        return (int) $statement->fetchColumn();
    }

    /**
     * Finds a project and optionally serializes mutations with allocations.
     *
     * @param int $id
     * @param bool $isLocked
     * @return ?array
     */
    public function findProject(int $id, bool $isLocked = false): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM projects WHERE id = :id' . ($isLocked ? ' FOR UPDATE' : ''));
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Lists projects in stable creation order.
     *
     * @return array
     */
    public function findProjects(): array
    {
        return $this->connection->query('SELECT * FROM projects ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Saves descriptive project fields independently from its stable ID and retries.
     *
     * @param int $id
     * @param string $name
     * @param ?string $repositoryUrl
     * @return void
     */
    public function saveProject(int $id, string $name, ?string $repositoryUrl): void
    {
        $statement = $this->connection->prepare('UPDATE projects SET name = :name, repository_url = :url WHERE id = :id');
        $statement->execute(['id' => $id, 'name' => $name, 'url' => $repositoryUrl]);
    }

    /**
     * Updates a locked counter while retaining its permanent allocation flag.
     *
     * @param int $id
     * @param int $number
     * @return void
     */
    public function saveCounter(int $id, int $number): void
    {
        $statement = $this->connection->prepare('UPDATE projects SET next_build_number = :number, is_exhausted = FALSE WHERE id = :id');
        $statement->execute(['id' => $id, 'number' => $number]);
    }

    /**
     * Archives or restores a project without deleting its tokens/history.
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
