<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Ordinal\Database\Transaction;
use Ordinal\Repository\AuditRepository;
use Ordinal\Repository\TokenRepository;
use Ordinal\Repository\ProjectRepository;
use Ordinal\Security\ProjectToken;
use PDO;

/** Generates, renames, rotates and revokes multiple named tokens per project. */
final readonly class ProjectTokenService
{
    /** Bounds human-readable token names. */
    private const int MAX_NAME_BYTES = 200;
    /** Bounds optional expiration to a practical interval. */
    private const int MAX_LIFETIME_DAYS = 36500;

    /**
     * Shares project/token locks and atomic audits with allocation.
     *
     * @param PDO $connection
     * @param TokenRepository $repository
     * @param ProjectRepository $projects
     * @param AuditRepository $audit
     */
    public function __construct(
        /** Owns token management transactions. */
        private PDO                  $connection,
        /** Stores hashes and stable token identities. */
        private TokenRepository $repository,
        /** Locks the owning project. */
        private ProjectRepository    $projects,
        /** Records token mutations without secrets. */
        private AuditRepository      $audit,
    ) {}

    /**
     * Shows metadata only; never returns stored hashes or reconstructs a secret.
     *
     * @param int $projectId
     * @return array
     */
    public function findProjectTokens(int $projectId): array
    {
        $this->requireProject($projectId);
        return $this->repository->findProjectTokens($projectId);
    }

    /**
     * Returns a generated token once; null lifetime means no expiration.
     *
     * @param int $projectId
     * @param string $name
     * @param ?int $lifetimeDays
     * @return array
     */
    public function createToken(int $projectId, string $name, ?int $lifetimeDays = null): array
    {
        $name = $this->requireName($name);
        $this->requireLifetime($lifetimeDays);
        $result = [];
        (new Transaction($this->connection))->execute(/**
             * Runs the operation on the transaction connection.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($projectId, $name, $lifetimeDays, &$result): void {
            $this->requireProject($projectId, true);
            $secret = ProjectToken::createSecret();
            $token = $this->repository->createToken($projectId, null, $name, hash('sha256', $secret), $lifetimeDays);
            $this->audit->recordEvent(null, $projectId, 'token_created', ['tokenId' => (int) $token['id']]);
            $result = $this->createReceipt($token, $secret);
        });
        return $result;
    }

    /**
     * Replaces a token secret without changing its name, identity or permanent retries.
     *
     * @param int $projectId
     * @param int $id
     * @param ?int $lifetimeDays
     * @return array
     */
    public function rotateToken(int $projectId, int $id, ?int $lifetimeDays = null): array
    {
        $this->requireLifetime($lifetimeDays);
        $result = [];
        (new Transaction($this->connection))->execute(/**
             * Runs the operation on the transaction connection.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($projectId, $id, $lifetimeDays, &$result): void {
            $token = $this->requireToken($projectId, $id);
            if ($token['revoked_at'] !== null) { throw new AccountException('Revoked tokens cannot be rotated.', 400); }
            $secret = ProjectToken::createSecret();
            $this->repository->rotateToken($id, hash('sha256', $secret), $lifetimeDays);
            $token = $this->repository->findToken($id);
            $this->audit->recordEvent(null, $projectId, 'token_rotated', ['tokenId' => $id]);
            $result = $this->createReceipt($token, $secret);
        });
        return $result;
    }

    /**
     * Revokes a named token immediately without deleting its history.
     *
     * @param int $projectId
     * @param int $id
     * @return void
     */
    public function revokeToken(int $projectId, int $id): void
    {
        (new Transaction($this->connection))->execute(/**
             * Runs the operation on the transaction connection.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($projectId, $id): void {
            $this->requireToken($projectId, $id);
            $this->repository->revokeToken($id);
            $this->audit->recordEvent(null, $projectId, 'token_revoked', ['tokenId' => $id]);
        });
    }

    /**
     * Renames a stable token without changing its secret or allocation history.
     *
     * @param int $projectId
     * @param int $id
     * @param string $name
     * @return void
     */
    public function renameToken(int $projectId, int $id, string $name): void
    {
        $name = $this->requireName($name);
        (new Transaction($this->connection))->execute(/**
             * Runs the operation on the transaction connection.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($projectId, $id, $name): void {
            $this->requireToken($projectId, $id);
            $this->repository->renameToken($id, $name);
            $this->audit->recordEvent(null, $projectId, 'token_renamed', ['tokenId' => $id]);
        });
    }

    /**
     * Locks tokens before projects to match the allocation lock order.
     *
     * @param int $projectId
     * @param int $id
     * @return array
     */
    private function requireToken(int $projectId, int $id): array
    {
        $token = $this->repository->findToken($id, true);
        if ($token === null || (int) $token['project_id'] !== $projectId) { throw new AccountException('Token not found for this project.', 404); }
        $this->requireProject($projectId, true);
        return $token;
    }

    /**
     * Requires an existing active owning project.
     *
     * @param int $id
     * @param bool $isLocked
     * @return array
     */
    private function requireProject(int $id, bool $isLocked = false): array
    {
        $project = $this->projects->findProject($id, $isLocked);
        if ($project === null || $project['archived_at'] !== null) { throw new AccountException('Active project not found.', 404); }
        return $project;
    }

    /**
     * Rejects blank or oversized token names.
     *
     * @param string $name
     * @return string
     */
    private function requireName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > self::MAX_NAME_BYTES) { throw new AccountException('Choose a token name up to 200 bytes.', 400); }
        return $name;
    }

    /**
     * Accepts no expiry or a positive bounded lifetime.
     *
     * @param ?int $days
     * @return void
     */
    private function requireLifetime(?int $days): void
    {
        if ($days !== null && ($days < 1 || $days > self::MAX_LIFETIME_DAYS)) { throw new AccountException('Use no expiration or 1 to 36500 days.', 400); }
    }

    /**
     * Builds the one-time secret response without returning a stored hash.
     *
     * @param array $token
     * @param string $secret
     * @return array
     */
    private function createReceipt(array $token, string $secret): array
    {
        return ['id' => (int) $token['id'], 'projectId' => (int) $token['project_id'], 'name' => $token['name'],
            'expiresAt' => $token['expires_at'], 'token' => ProjectToken::createToken((int) $token['id'], $secret)];
    }
}
