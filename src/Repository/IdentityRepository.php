<?php

declare(strict_types=1);

namespace Ordinal\Repository;

use Ordinal\Model\ProviderIdentity;
use PDO;

/** Persists provider-qualified identities without treating account existence as repository access. */
final readonly class IdentityRepository
{
    /**
     * Uses the configured PDO connection.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Stores identity records. */
        private PDO $connection,
    ) {}

    /**
     * Updates names while keeping the provider-qualified immutable identity unique.
     *
     * @param ProviderIdentity $identity
     * @return int
     */
    public function saveIdentity(ProviderIdentity $identity): int
    {
        $statement = $this->connection->prepare('INSERT INTO users (provider_connection_id, provider_user_id, user_name, display_name) VALUES (:connection, :external, :username, :name) ON CONFLICT (provider_connection_id, provider_user_id) DO UPDATE SET user_name = EXCLUDED.user_name, display_name = EXCLUDED.display_name RETURNING id');
        $statement->execute(['connection' => $identity->providerConnectionId, 'external' => $identity->providerUserId, 'username' => $identity->userName, 'name' => $identity->displayName]);
        return (int) $statement->fetchColumn();
    }

    /**
     * Finds one local user by stable ID.
     *
     * @param int $userId
     * @return ?array
     */
    public function findUser(int $userId): ?array
    {
        $statement = $this->connection->prepare('SELECT u.*, p.registration_reference FROM users u JOIN provider_connections p ON p.id = u.provider_connection_id WHERE u.id = :id');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Lists identity records for protected administrator selection.
     *
     * @return array
     */
    public function findUsers(): array
    {
        return $this->connection->query('SELECT u.*, a.revoked_at IS NULL AND a.id IS NOT NULL AS is_administrator FROM users u LEFT JOIN instance_administrators a ON a.user_id = u.id ORDER BY u.id')->fetchAll(PDO::FETCH_ASSOC);
    }
}
