<?php

declare(strict_types=1);

namespace Ordinal\Repository;

use Ordinal\Security\AuthenticationException;
use PDO;
use SensitiveParameter;

/** Stores hashed project credentials and coordinates device revocation with allocation locks. */
final readonly class DeviceRepository
{
    /**
     * Uses the owning service's transaction connection.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Persists device identities and credentials. */
        private PDO $connection,
    ) {}

    /**
     * Reads effective policy and repository state.
     *
     * @param int $projectId
     * @param bool $isLocked
     * @return ?array
     */
    public function findProjectPolicy(int $projectId, bool $isLocked = false): ?array
    {
        return (new ProjectRepository($this->connection))->findProject($projectId, $isLocked);
    }

    /**
     * Creates a stable device independent of credential renewal.
     *
     * @param int $userId
     * @param string $name
     * @return int
     */
    public function createDevice(int $userId, string $name): int
    {
        $statement = $this->connection->prepare('INSERT INTO devices (user_id, name) VALUES (:user, :name) RETURNING id');
        $statement->execute(['user' => $userId, 'name' => $name]);
        return (int) $statement->fetchColumn();
    }

    /**
     * Locks a device before any credential or project locks.
     *
     * @param int $id
     * @return ?array
     */
    public function lockDevice(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM devices WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Reads current validity; locks device then credential when requested.
     *
     * @param int $id
     * @param bool $isLocked
     * @return ?array
     */
    public function findCredential(int $id, bool $isLocked = false): ?array
    {
        if ($isLocked) {
            $device = $this->connection->prepare('SELECT d.id FROM devices d JOIN device_credentials c ON c.device_id = d.id WHERE c.id = :id FOR UPDATE OF d');
            $device->execute(['id' => $id]);
            if ($device->fetchColumn() === false) {
                return null;
            }
        }
        $statement = $this->connection->prepare('SELECT c.*, d.revoked_at AS device_revoked_at,
            u.provider_connection_id, u.provider_user_id, u.display_name,
            (c.expires_at IS NOT NULL AND c.expires_at <= clock_timestamp()) AS is_expired
            FROM device_credentials c JOIN devices d ON d.id = c.device_id JOIN users u ON u.id = c.user_id
            WHERE c.id = :id' . ($isLocked ? ' FOR UPDATE OF c' : ''));
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Rechecks identity, scope, absolute expiry, and revocation.
     *
     * @param ?array $credential
     * @param int $projectId
     * @param int $userId
     * @return void
     * @throws AuthenticationException
     */
    public function requireActiveCredential(?array $credential, int $projectId, int $userId): void
    {
        if ($credential === null || (int) $credential['user_id'] !== $userId || (int) $credential['project_id'] !== $projectId
            || $credential['revoked_at'] !== null || $credential['device_revoked_at'] !== null || $credential['is_expired']
            || ($credential['lifetime_days'] === 0 && $credential['provider_sign_in_at'] === null)) {
            $reason = ($credential['revoked_at'] ?? null) !== null || ($credential['device_revoked_at'] ?? null) !== null
                ? 'CREDENTIAL_REVOKED' : (($credential['is_expired'] ?? false) ? 'CREDENTIAL_EXPIRED' : null);
            throw new AuthenticationException('Device authentication is required or invalid.', reason: $reason);
        }
    }

    /**
     * Saves only a hash and a fixed sign-in-based expiry; returns null for a reused single-use sign-in.
     *
     * @param int $deviceId
     * @param int $userId
     * @param int $projectId
     * @param string $hash
     * @param int $lifetimeDays
     * @param string $authenticatedAt
     * @return ?array
     */
    public function createCredential(
        int    $deviceId,
        int    $userId,
        int    $projectId,
        #[SensitiveParameter]
        string $hash,
        int    $lifetimeDays,
        string $authenticatedAt,
    ): ?array {
        $statement = $this->connection->prepare('INSERT INTO device_credentials
            (device_id, user_id, project_id, secret_hash, lifetime_days, authenticated_at, provider_sign_in_at, expires_at)
            VALUES (:device, :user, :project, :hash, :days, CAST(:authenticated AS TIMESTAMPTZ), CAST(:signIn AS TIMESTAMPTZ),
            CASE WHEN CAST(:expiryDays AS INTEGER) > 0 THEN CAST(:expiryStart AS TIMESTAMPTZ) + make_interval(days => CAST(:expiryLength AS INTEGER)) ELSE NULL END)
            ON CONFLICT (user_id, project_id, provider_sign_in_at) WHERE lifetime_days = 0 AND provider_sign_in_at IS NOT NULL DO NOTHING RETURNING *');
        $statement->execute(['device' => $deviceId, 'user' => $userId, 'project' => $projectId, 'hash' => $hash, 'days' => $lifetimeDays,
            'authenticated' => $authenticatedAt, 'signIn' => $authenticatedAt, 'expiryDays' => $lifetimeDays, 'expiryStart' => $authenticatedAt, 'expiryLength' => $lifetimeDays]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Consumes the locked credential atomically with its originating allocation.
     *
     * @param int $credentialId
     * @param int $allocationId
     * @return void
     */
    public function consumeCredential(int $credentialId, int $allocationId): void
    {
        $statement = $this->connection->prepare('UPDATE device_credentials SET consumed_allocation_id = :allocation WHERE id = :id AND lifetime_days = 0 AND consumed_allocation_id IS NULL');
        $statement->execute(['allocation' => $allocationId, 'id' => $credentialId]);
    }

    /**
     * Revokes a locked credential while retaining retry/audit relationships.
     *
     * @param int $id
     * @return void
     */
    public function revokeCredential(int $id): void
    {
        $statement = $this->connection->prepare('UPDATE device_credentials SET revoked_at = COALESCE(revoked_at, clock_timestamp()) WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Revokes a locked device and all its project credentials in one transaction.
     *
     * @param int $id
     * @return void
     */
    public function revokeDevice(int $id): void
    {
        $statement = $this->connection->prepare('UPDATE devices SET revoked_at = COALESCE(revoked_at, clock_timestamp()) WHERE id = :id');
        $statement->execute(['id' => $id]);
        $statement = $this->connection->prepare('UPDATE device_credentials SET revoked_at = COALESCE(revoked_at, clock_timestamp()) WHERE device_id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Lists credential metadata without secret hashes.
     *
     * @param int $userId
     * @param bool $isAdministrator
     * @param ?int $projectId
     * @param bool $canReadProjectCredentials
     * @return array
     */
    public function findDeviceData(
        int   $userId,
        bool  $isAdministrator,
        ?int  $projectId                 = null,
        bool  $canReadProjectCredentials = false,
    ): array
    {
        $filter = $isAdministrator ? '' : ' WHERE d.user_id = :user';
        $statement = $this->connection->prepare('SELECT d.* FROM devices d' . $filter . ' ORDER BY d.id');
        $parameters = $isAdministrator ? [] : ['user' => $userId];
        $statement->execute($parameters);
        $devices = $statement->fetchAll(PDO::FETCH_ASSOC);
        $conditions = [];
        $parameters = [];
        if (!$isAdministrator && !($projectId !== null && $canReadProjectCredentials)) {
            $conditions[] = 'd.user_id = :user';
            $parameters['user'] = $userId;
        }
        if ($projectId !== null) {
            $conditions[] = 'c.project_id = :project';
            $parameters['project'] = $projectId;
        }
        $filter = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $statement = $this->connection->prepare('SELECT c.id, c.device_id, c.user_id, c.project_id, c.authenticated_at, c.lifetime_days, c.expires_at, c.revoked_at, c.consumed_allocation_id, p.name AS project_name
            FROM device_credentials c JOIN devices d ON d.id = c.device_id JOIN projects p ON p.id = c.project_id' . $filter . ' ORDER BY c.id');
        $statement->execute($parameters);
        return ['devices' => $devices, 'credentials' => $statement->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
     * Changes future authentication policy without changing issued credentials.
     *
     * @param ?int $projectId
     * @param ?bool $isRequired
     * @param ?int $lifetimeDays
     * @return void
     */
    public function saveAuthenticationPolicy(?int $projectId, ?bool $isRequired, ?int $lifetimeDays): void
    {
        $statement = $this->connection->prepare($projectId === null
            ? 'UPDATE instance_settings SET is_authentication_required = :required, device_lifetime_days = :days WHERE id = 1'
            : 'UPDATE projects SET authentication_required_override = :required, device_lifetime_days_override = :days WHERE id = :project');
        $statement->bindValue('required', $isRequired, $isRequired === null ? PDO::PARAM_NULL : PDO::PARAM_BOOL);
        $statement->bindValue('days', $lifetimeDays, $lifetimeDays === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        if ($projectId !== null) {
            $statement->bindValue('project', $projectId, PDO::PARAM_INT);
        }
        $statement->execute();
    }
}
