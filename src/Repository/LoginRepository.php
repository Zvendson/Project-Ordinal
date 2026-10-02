<?php

declare(strict_types=1);

namespace Ordinal\Repository;

use PDO;
use SensitiveParameter;

/** Stores short-lived OAuth state and consumes a browser-bound attempt atomically. */
final readonly class LoginRepository
{
    /** Limits login state validity independently of browser session policy. */
    public const int LIFETIME_SECONDS = 600;

    /**
     * Uses application persistence for callbacks across independent browser requests.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Persists hashed state/browser binding and encrypted verifiers. */
        private PDO $connection,
    ) {}

    /**
     * Creates a short-lived attempt and clears expired attempts.
     *
     * @param int $connectionId
     * @param string $stateHash
     * @param string $browserHash
     * @param string $encryptedVerifier
     * @param ?int $sessionId
     * @return void
     */
    public function createAttempt(int $connectionId, #[SensitiveParameter] string $stateHash, #[SensitiveParameter] string $browserHash, #[SensitiveParameter] string $encryptedVerifier, ?int $sessionId): void
    {
        $this->connection->exec('DELETE FROM oauth_attempts WHERE expires_at <= clock_timestamp()');
        $statement = $this->connection->prepare("INSERT INTO oauth_attempts (provider_connection_id, state_hash, browser_hash, encrypted_verifier, browser_session_id, expires_at) VALUES (:connection, :state, :browser, decode(:verifier, 'hex'), :session, clock_timestamp() + make_interval(secs => :lifetime))");
        $statement->execute(['connection' => $connectionId, 'state' => $stateHash, 'browser' => $browserHash, 'verifier' => bin2hex($encryptedVerifier), 'session' => $sessionId, 'lifetime' => self::LIFETIME_SECONDS]);
    }

    /**
     * Consumes only a matching unexpired attempt; a wrong browser cannot consume another browser's state.
     *
     * @param string $stateHash
     * @param string $browserHash
     * @return ?array
     */
    public function consumeAttempt(#[SensitiveParameter] string $stateHash, #[SensitiveParameter] string $browserHash): ?array
    {
        $statement = $this->connection->prepare("DELETE FROM oauth_attempts WHERE state_hash = :state AND browser_hash = :browser AND expires_at > clock_timestamp() RETURNING *, encode(encrypted_verifier, 'hex') AS verifier");
        $statement->execute(['state' => $stateHash, 'browser' => $browserHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
