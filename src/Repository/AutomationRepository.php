<?php

declare(strict_types=1);

namespace Ordinal\Repository;

use Ordinal\Security\AuthenticationException;
use PDO;
use SensitiveParameter;

/** Stores named hash-only automation tokens and coordinates rotation/revocation with allocation. */
final readonly class AutomationRepository
{
    /**
     * Uses the owning application's transaction connection.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Persists stable token identities and assigned expiry. */
        private PDO $connection,
    ) {}

    /**
     * Reads current validity and optionally locks the stable token before project locks.
     *
     * @param int $id
     * @param bool $isLocked
     * @return ?array
     */
    public function findToken(int $id, bool $isLocked = false): ?array
    {
        if ($isLocked) {
            // Evaluate expiration in a separate statement after any lock wait completes.
            $lock = $this->connection->prepare('SELECT id FROM automation_tokens WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $id]);
            if ($lock->fetchColumn() === false) {
                return null;
            }
        }
        $statement = $this->connection->prepare('SELECT t.*, (t.expires_at IS NOT NULL AND t.expires_at <= clock_timestamp()) AS is_expired FROM automation_tokens t WHERE t.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Rechecks scope, assigned expiry, revocation, and the verified hash after waiting for locks.
     *
     * @param ?array $token
     * @param int $projectId
     * @param string $secretHash
     * @return void
     */
    public function requireActiveToken(?array $token, int $projectId, #[SensitiveParameter] string $secretHash): void
    {
        if ($token === null || (int) $token['project_id'] !== $projectId || $token['revoked_at'] !== null || $token['is_expired']
            || !hash_equals($token['secret_hash'], $secretHash)) {
            throw new AuthenticationException('Automation authentication is required or invalid.');
        }
    }

    /**
     * Creates a stable token; null lifetime is a separately authorized no-expiration request.
     *
     * @param int $projectId
     * @param int $actorId
     * @param string $name
     * @param string $hash
     * @param ?int $lifetimeDays
     * @return array
     */
    public function createToken(int $projectId, int $actorId, string $name, #[SensitiveParameter] string $hash, ?int $lifetimeDays): array
    {
        $statement = $this->connection->prepare('INSERT INTO automation_tokens (project_id, created_by_user_id, name, secret_hash, created_at, expires_at)
            VALUES (:project, :actor, :name, :hash, statement_timestamp(), CASE WHEN CAST(:days AS INTEGER) IS NULL THEN NULL ELSE statement_timestamp() + make_interval(days => CAST(:length AS INTEGER)) END) RETURNING *');
        $statement->execute(['project' => $projectId, 'actor' => $actorId, 'name' => $name, 'hash' => $hash, 'days' => $lifetimeDays, 'length' => $lifetimeDays]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Replaces only the secret and expiry, preserving stable token ID and original creator/history.
     *
     * @param int $id
     * @param string $hash
     * @param ?int $lifetimeDays
     * @return array
     */
    public function rotateToken(int $id, #[SensitiveParameter] string $hash, ?int $lifetimeDays): array
    {
        $statement = $this->connection->prepare('UPDATE automation_tokens SET secret_hash = :hash, rotated_at = statement_timestamp(),
            expires_at = CASE WHEN CAST(:days AS INTEGER) IS NULL THEN NULL ELSE statement_timestamp() + make_interval(days => CAST(:length AS INTEGER)) END WHERE id = :id RETURNING *');
        $statement->execute(['id' => $id, 'hash' => $hash, 'days' => $lifetimeDays, 'length' => $lifetimeDays]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Edits a readable name without changing permission, secret, or historical identity.
     *
     * @param int $id
     * @param string $name
     * @return void
     */
    public function renameToken(int $id, string $name): void
    {
        $statement = $this->connection->prepare('UPDATE automation_tokens SET name = :name WHERE id = :id');
        $statement->execute(['id' => $id, 'name' => $name]);
    }

    /**
     * Revokes the locked token without deleting allocation/audit references.
     *
     * @param int $id
     * @return void
     */
    public function revokeToken(int $id): void
    {
        $statement = $this->connection->prepare('UPDATE automation_tokens SET revoked_at = COALESCE(revoked_at, statement_timestamp()) WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Lists only metadata without credential hashes.
     *
     * @param int $projectId
     * @return array
     */
    public function findProjectTokens(int $projectId): array
    {
        $statement = $this->connection->prepare('SELECT id, project_id, created_by_user_id, name, created_at, expires_at, revoked_at, rotated_at FROM automation_tokens WHERE project_id = :id ORDER BY id');
        $statement->execute(['id' => $projectId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Reads the current instance-controlled automation policy.
     *
     * @return array
     */
    public function getPolicy(): array
    {
        return $this->connection->query('SELECT automation_lifetime_days, is_automation_without_expiration_allowed FROM instance_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Changes future issuance/rotation policy while preserving existing tokens.
     *
     * @param int $lifetimeDays
     * @param bool $isWithoutExpirationAllowed
     * @return void
     */
    public function savePolicy(int $lifetimeDays, bool $isWithoutExpirationAllowed): void
    {
        $statement = $this->connection->prepare('UPDATE instance_settings SET automation_lifetime_days = :days, is_automation_without_expiration_allowed = :allowed WHERE id = 1');
        $statement->bindValue('days', $lifetimeDays, PDO::PARAM_INT);
        $statement->bindValue('allowed', $isWithoutExpirationAllowed, PDO::PARAM_BOOL);
        $statement->execute();
    }
}
