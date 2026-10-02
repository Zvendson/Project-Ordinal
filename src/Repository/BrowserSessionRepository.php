<?php

declare(strict_types=1);

namespace Ordinal\Repository;

use PDO;
use SensitiveParameter;

/** Persists hashed browser credentials with fixed absolute expiry and per-session idle limits. */
final readonly class BrowserSessionRepository
{
    /**
     * Uses the application database for session validity.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Stores sessions and checks revocation and database-clock expiry. */
        private PDO $connection,
    ) {}

    /**
     * Creates a fresh session from current instance policy; no browser-supplied identifier is reused.
     *
     * @param int $userId
     * @param string $secretHash
     * @param string $csrfToken
     * @return void
     */
    public function createSession(
        int    $userId,
        #[SensitiveParameter]
        string $secretHash,
        #[SensitiveParameter]
        string $csrfToken,
    ): void
    {
        $statement = $this->connection->prepare("INSERT INTO browser_sessions (user_id, secret_hash, csrf_token, idle_minutes, absolute_minutes, expires_at, provider_reauthenticated_at) SELECT :user, :secret, :csrf, browser_idle_minutes, browser_absolute_minutes, clock_timestamp() + make_interval(mins => browser_absolute_minutes), clock_timestamp() FROM instance_settings WHERE id = 1");
        $statement->execute(['user' => $userId, 'secret' => $secretHash, 'csrf' => $csrfToken]);
    }

    /**
     * Validates and touches a hashed cookie without sliding its absolute expiry.
     *
     * @param string $secretHash
     * @return ?array
     */
    public function authenticate(
        #[SensitiveParameter]
        string $secretHash,
    ): ?array
    {
        $statement = $this->connection->prepare("UPDATE browser_sessions s SET last_activity_at = clock_timestamp() FROM users u, provider_connections p, provider_authorizations a WHERE s.secret_hash = :secret AND u.id = s.user_id AND p.id = u.provider_connection_id AND a.user_id = u.id AND s.revoked_at IS NULL AND p.disabled_at IS NULL AND a.revoked_at IS NULL AND s.expires_at > clock_timestamp() AND s.last_activity_at + make_interval(mins => s.idle_minutes) > clock_timestamp() RETURNING s.*, u.provider_connection_id, u.provider_user_id, u.display_name");
        $statement->execute(['secret' => $secretHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Rechecks a session for protected actions and optionally locks it through the mutation.
     *
     * @param int $id
     * @param bool $isLocked
     * @return ?array
     */
    public function findActiveSession(int $id, bool $isLocked = false): ?array
    {
        $statement = $this->connection->prepare("SELECT s.*, u.provider_connection_id, u.provider_user_id, u.display_name FROM browser_sessions s JOIN users u ON u.id = s.user_id JOIN provider_connections p ON p.id = u.provider_connection_id JOIN provider_authorizations a ON a.user_id = u.id WHERE s.id = :id AND s.revoked_at IS NULL AND p.disabled_at IS NULL AND a.revoked_at IS NULL AND s.expires_at > clock_timestamp() AND s.last_activity_at + make_interval(mins => s.idle_minutes) > clock_timestamp()" . ($isLocked ? ' FOR UPDATE OF s' : ''));
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Records logout while retaining the stable session row.
     *
     * @param int $id
     * @return void
     */
    public function revokeSession(int $id): void
    {
        $statement = $this->connection->prepare("WITH revoked AS (UPDATE browser_sessions SET revoked_at = clock_timestamp() WHERE id = :id AND revoked_at IS NULL RETURNING user_id)
            INSERT INTO audit_events (user_id, action, outcome) SELECT user_id, 'browser_logout', 'success' FROM revoked");
        $statement->execute(['id' => $id]);
    }

    /**
     * Rotates the cookie/CSRF credentials after provider reauthentication without extending absolute expiry.
     *
     * @param int $id
     * @param string $secretHash
     * @param string $csrfToken
     * @return void
     */
    public function reauthenticateSession(
        int    $id,
        #[SensitiveParameter]
        string $secretHash,
        #[SensitiveParameter]
        string $csrfToken,
    ): void
    {
        $statement = $this->connection->prepare('UPDATE browser_sessions SET secret_hash = :secret, csrf_token = :csrf, provider_reauthenticated_at = clock_timestamp(), last_activity_at = clock_timestamp() WHERE id = :id AND revoked_at IS NULL');
        $statement->execute(['id' => $id, 'secret' => $secretHash, 'csrf' => $csrfToken]);
    }
}
