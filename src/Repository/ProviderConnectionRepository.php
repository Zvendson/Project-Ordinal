<?php

declare(strict_types=1);

namespace Ordinal\Repository;

use PDO;

/** Stores stable allowed provider connections while registration secrets remain external. */
final readonly class ProviderConnectionRepository
{
    /**
     * Uses the application's database connection.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Provides provider connection persistence. */
        private PDO $connection,
    ) {}

    /**
     * Creates the operator-configured initial sign-in connection without re-enabling existing records.
     *
     * @param string $reference
     * @param string $kind
     * @param string $serverUrl
     * @return void
     */
    public function initializeBootstrapConnection(string $reference, string $kind, string $serverUrl): void
    {
        $statement = $this->connection->prepare('INSERT INTO provider_connections (registration_reference, provider_kind, server_url, name) VALUES (:reference, :kind, :url, :name) ON CONFLICT (registration_reference) WHERE registration_reference IS NOT NULL DO NOTHING');
        $statement->execute(['reference' => $reference, 'kind' => $kind, 'url' => $serverUrl, 'name' => $reference]);
    }

    /**
     * Inserts a registration-backed connection or updates its permitted metadata.
     *
     * @param string $reference
     * @param string $kind
     * @param string $serverUrl
     * @param string $name
     * @param bool $isEnabled
     * @return int
     */
    public function saveConnection(string $reference, string $kind, string $serverUrl, string $name, bool $isEnabled): int
    {
        $statement = $this->connection->prepare('INSERT INTO provider_connections (registration_reference, provider_kind, server_url, name, disabled_at) VALUES (:reference, :kind, :url, :name, CASE WHEN :enabled = 1 THEN NULL ELSE clock_timestamp() END) ON CONFLICT (registration_reference) WHERE registration_reference IS NOT NULL DO UPDATE SET name = EXCLUDED.name, disabled_at = EXCLUDED.disabled_at RETURNING id');
        $statement->execute(['reference' => $reference, 'kind' => $kind, 'url' => $serverUrl, 'name' => $name, 'enabled' => $isEnabled ? 1 : 0]);
        return (int) $statement->fetchColumn();
    }

    /**
     * Finds one stable connection, including disabled records.
     *
     * @param int $id
     * @return ?array
     */
    public function findConnection(int $id): ?array
    {
        $statement = $this->connection->prepare('SELECT * FROM provider_connections WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Returns non-secret connection metadata in stable order.
     *
     * @return array
     */
    public function findConnections(): array
    {
        return $this->connection->query('SELECT * FROM provider_connections ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
}
