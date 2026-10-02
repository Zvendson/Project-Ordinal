<?php

declare(strict_types=1);

namespace Ordinal\Repository;

use DateTimeImmutable;
use Ordinal\Model\ProviderAuthorization;
use Ordinal\Security\AuthenticationException;
use Ordinal\Security\TokenCipher;
use PDO;
use SensitiveParameter;

/** Stores encrypted credentials and uses the user row as the shared login/refresh mutex. */
final readonly class AuthorizationRepository
{
    /**
     * Binds encrypted persistence to the database and operator-managed key.
     *
     * @param PDO $connection
     * @param TokenCipher $cipher
     */
    public function __construct(
        /** Persists authorizations within caller-owned transactions. */
        private PDO         $connection,
        /** Authenticates ciphertext against its record/field context. */
        private TokenCipher $cipher,
    ) {}

    /**
     * Locks the identity row before login writes or refresh reads/writes.
     *
     * @param int $userId
     * @return array
     * @throws AuthenticationException
     */
    public function lockUser(int $userId): array
    {
        if (!$this->connection->inTransaction()) {
            throw new \LogicException('Authorization locking requires a transaction.');
        }
        $statement = $this->connection->prepare('SELECT u.*, p.disabled_at FROM users u JOIN provider_connections p ON p.id = u.provider_connection_id WHERE u.id = :id FOR UPDATE OF u');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['disabled_at'] !== null) {
            throw new AuthenticationException('Authentication is required or invalid.');
        }
        return $row;
    }

    /**
     * Encrypts both token fields using provider/user/field context and advances the write version.
     *
     * @param int $userId
     * @param ProviderAuthorization $authorization
     * @return void
     */
    public function saveAuthorization(int $userId, #[SensitiveParameter] ProviderAuthorization $authorization): void
    {
        $user = $this->lockUser($userId);
        if ((int) $user['provider_connection_id'] !== $authorization->providerConnectionId) {
            throw new AuthenticationException('Authorization belongs to another provider connection.');
        }
        $statement = $this->connection->prepare("INSERT INTO provider_authorizations (user_id, encrypted_access_token, encrypted_refresh_token, access_expires_at, refresh_expires_at) VALUES (:user, decode(:access, 'hex'), decode(:refresh, 'hex'), :accessExpiry, :refreshExpiry) ON CONFLICT (user_id) DO UPDATE SET encrypted_access_token = EXCLUDED.encrypted_access_token, encrypted_refresh_token = EXCLUDED.encrypted_refresh_token, access_expires_at = EXCLUDED.access_expires_at, refresh_expires_at = EXCLUDED.refresh_expires_at, refresh_version = provider_authorizations.refresh_version + 1, updated_at = clock_timestamp(), revoked_at = NULL");
        $statement->execute([
            'user' => $userId, 'access' => bin2hex($this->cipher->encrypt($authorization->accessToken, $this->createContext($userId, $authorization->providerConnectionId, 'access'))),
            'refresh' => $authorization->refreshToken === null ? null : bin2hex($this->cipher->encrypt($authorization->refreshToken, $this->createContext($userId, $authorization->providerConnectionId, 'refresh'))),
            'accessExpiry' => $authorization->expiresAt?->format('c'), 'refreshExpiry' => $authorization->refreshExpiresAt?->format('c'),
        ]);
    }

    /**
     * Reads encrypted tokens after the caller has obtained the shared user lock.
     *
     * @param int $userId
     * @param int $providerConnectionId
     * @return ProviderAuthorization
     * @throws AuthenticationException
     */
    public function findAuthorization(int $userId, int $providerConnectionId): ProviderAuthorization
    {
        $statement = $this->connection->prepare("SELECT *, encode(encrypted_access_token, 'hex') AS access, encode(encrypted_refresh_token, 'hex') AS refresh FROM provider_authorizations WHERE user_id = :id AND revoked_at IS NULL");
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new AuthenticationException('Provider sign-in is required.');
        }
        return new ProviderAuthorization($providerConnectionId,
            $this->cipher->decrypt(hex2bin($row['access']), $this->createContext($userId, $providerConnectionId, 'access')),
            $row['refresh'] === null ? null : $this->cipher->decrypt(hex2bin($row['refresh']), $this->createContext($userId, $providerConnectionId, 'refresh')),
            $row['access_expires_at'] === null ? null : new DateTimeImmutable($row['access_expires_at']),
            $row['refresh_expires_at'] === null ? null : new DateTimeImmutable($row['refresh_expires_at']),
        );
    }

    /**
     * Binds a ciphertext to the immutable local user, provider connection, and token field.
     *
     * @param int $userId
     * @param int $connectionId
     * @param string $field
     * @return string
     */
    private function createContext(int $userId, int $connectionId, string $field): string
    {
        return 'provider:' . $connectionId . ':user:' . $userId . ':' . $field;
    }

    /**
     * Reads the write version under the shared identity mutex.
     *
     * @param int $userId
     * @return int
     */
    public function getVersion(int $userId): int
    {
        $statement = $this->connection->prepare('SELECT refresh_version FROM provider_authorizations WHERE user_id = :id');
        $statement->execute(['id' => $userId]);
        $version = $statement->fetchColumn();
        return $version === false ? -1 : (int) $version;
    }

    /**
     * Revokes a rejected token pair only if a newer login has not already replaced it.
     *
     * @param int $userId
     * @param int $version
     * @return void
     */
    public function revokeAuthorization(int $userId, int $version): void
    {
        $statement = $this->connection->prepare('UPDATE provider_authorizations SET revoked_at = clock_timestamp() WHERE user_id = :id AND refresh_version = :version AND revoked_at IS NULL RETURNING id');
        $statement->execute(['id' => $userId, 'version' => $version]);
        if ($statement->fetchColumn() !== false) {
            $this->connection->prepare('UPDATE browser_sessions SET revoked_at = clock_timestamp() WHERE user_id = :user AND revoked_at IS NULL')->execute(['user' => $userId]);
            $this->connection->prepare("INSERT INTO audit_events (user_id, action, outcome) VALUES (:user, 'provider_authorization_revoked', 'success')")->execute(['user' => $userId]);
        }
    }
}
