<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Ordinal\Database\Transaction;
use Ordinal\Repository\AuditRepository;
use Ordinal\Repository\ProjectRepository;
use PDO;

/** Manages independent projects and serializes counter changes with build allocation. */
final readonly class ProjectService
{
    /** Bounds all counter values to uint32. */
    private const int MAX_BUILD_NUMBER = 4294967295;
    /** Bounds names shown throughout the interface. */
    private const int MAX_NAME_BYTES = 200;
    /** Bounds optional repository links. */
    private const int MAX_URL_BYTES = 2048;

    /**
     * Uses shared persistence and audit in each management transaction.
     *
     * @param PDO $connection
     * @param ProjectRepository $repository
     * @param AuditRepository $audit
     */
    public function __construct(
        /** Owns project mutation transactions. */
        private PDO               $connection,
        /** Reads and writes project data. */
        private ProjectRepository $repository,
        /** Records administrative changes atomically. */
        private AuditRepository   $audit,
    ) {}

    /**
     * Creates a named project with an optional URL; never contacts its hosting provider.
     *
     * @param string $name
     * @param ?string $repositoryUrl
     * @return int
     */
    public function createProject(string $name, ?string $repositoryUrl = null): int
    {
        $name = $this->requireName($name);
        $url = $this->normalizeRepositoryUrl($repositoryUrl);
        $id = 0;
        (new Transaction($this->connection))->execute(/**
             * Runs the operation on the transaction connection.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($name, $url, &$id): void {
            $id = $this->repository->createProject($name, $url);
            $this->audit->recordEvent(null, $id, 'project_created');
        });
        return $id;
    }

    /**
     * Lists all projects for the authenticated local administrator.
     *
     * @return array
     */
    public function findProjects(): array { return $this->repository->findProjects(); }

    /**
     * Returns an existing project or a fixed not-found error.
     *
     * @param int $id
     * @return array
     */
    public function getProject(int $id): array
    {
        return $this->repository->findProject($id) ?? throw new AccountException('Project not found.', 404);
    }

    /**
     * Edits project name/link without changing its counter or token scope.
     *
     * @param int $id
     * @param string $name
     * @param ?string $repositoryUrl
     * @return void
     */
    public function saveProject(int $id, string $name, ?string $repositoryUrl): void
    {
        $name = $this->requireName($name);
        $url = $this->normalizeRepositoryUrl($repositoryUrl);
        (new Transaction($this->connection))->execute(/**
             * Runs the operation on the transaction connection.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($id, $name, $url): void {
            $this->requireActiveProject($id);
            $this->repository->saveProject($id, $name, $url);
            $this->audit->recordEvent(null, $id, 'project_updated');
        });
    }

    /**
     * Allows initial counter edits and later increases without accidental number reuse.
     *
     * @param int $id
     * @param int $number
     * @return void
     */
    public function saveCounter(int $id, int $number): void
    {
        $this->requireNumber($number);
        (new Transaction($this->connection))->execute(/**
             * Runs the operation on the transaction connection.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($id, $number): void {
            $project = $this->requireActiveProject($id);
            if ($project['has_allocated_build_number'] && ($project['is_exhausted'] || $number <= $project['next_build_number'])) {
                throw new AccountException('After a build, choose a greater number or use the confirmed reset.', 400);
            }
            $this->repository->saveCounter($id, $number);
            $this->audit->recordEvent(null, $id, 'counter_updated', ['nextBuildNumber' => $number]);
        });
    }

    /**
     * Resets a counter only after explicit name/reuse confirmation; preserves permanent retries.
     *
     * @param int $id
     * @param int $number
     * @param string $projectName
     * @param bool $isReuseConfirmed
     * @return void
     */
    public function resetCounter(int $id, int $number, string $projectName, bool $isReuseConfirmed): void
    {
        $this->requireNumber($number);
        (new Transaction($this->connection))->execute(/**
             * Runs the operation on the transaction connection.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($id, $number, $projectName, $isReuseConfirmed): void {
            $project = $this->requireActiveProject($id);
            if (!$isReuseConfirmed || $projectName !== $project['name']) { throw new AccountException('Confirm the project name and number reuse before resetting.', 400); }
            $this->repository->saveCounter($id, $number);
            $this->audit->recordEvent(null, $id, 'counter_reset', ['nextBuildNumber' => $number]);
        });
    }

    /**
     * Archives/reactivates without deleting counters, tokens or retry records.
     *
     * @param int $id
     * @param bool $isArchived
     * @return void
     */
    public function saveArchiveState(int $id, bool $isArchived): void
    {
        (new Transaction($this->connection))->execute(/**
             * Runs the operation on the transaction connection.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($id, $isArchived): void {
            if ($this->repository->findProject($id, true) === null) { throw new AccountException('Project not found.', 404); }
            $this->repository->saveArchiveState($id, $isArchived);
            $this->audit->recordEvent(null, $id, $isArchived ? 'project_archived' : 'project_reactivated');
        });
    }

    /**
     * Obtains the same project lock used by allocation and rejects archived projects.
     *
     * @param int $id
     * @return array
     */
    private function requireActiveProject(int $id): array
    {
        $project = $this->repository->findProject($id, true);
        if ($project === null || $project['archived_at'] !== null) { throw new AccountException('Active project not found.', 404); }
        return $project;
    }

    /**
     * Rejects empty/oversized names instead of silently truncating them.
     *
     * @param string $name
     * @return string
     */
    private function requireName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > self::MAX_NAME_BYTES) { throw new AccountException('Choose a project name up to 200 bytes.', 400); }
        return $name;
    }

    /**
     * Accepts optional HTTP(S) repository links without embedded credentials or fetching them.
     *
     * @param ?string $url
     * @return ?string
     */
    private function normalizeRepositoryUrl(?string $url): ?string
    {
        $url = trim($url ?? '');
        if ($url === '') { return null; }
        $parts = parse_url($url);
        if (strlen($url) > self::MAX_URL_BYTES || filter_var($url, FILTER_VALIDATE_URL) === false || $parts === false
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) { throw new AccountException('Use a valid repository URL, or leave it empty.', 400); }
        return $url;
    }

    /**
     * Requires the inclusive uint32 counter range.
     *
     * @param int $number
     * @return void
     */
    private function requireNumber(int $number): void
    {
        if ($number < 0 || $number > self::MAX_BUILD_NUMBER) { throw new AccountException('Choose a number from 0 to 4294967295.', 400); }
    }
}
