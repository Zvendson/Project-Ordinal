<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Ordinal\Database\Transaction;
use Ordinal\Model\AdministratorSession;
use Ordinal\Security\AuthenticationException;
use Ordinal\Security\CsrfProtection;
use PDO;
use SensitiveParameter;

/** Verifies one administrator password and protects browser sessions and forms. */
final readonly class AdministratorService
{
    /** Names the secure local management cookie. */
    public const string COOKIE_NAME = '__Host-ordinal-admin';
    /** Limits sign-in failures per client in a fixed five-minute window. */
    private const int MAX_ATTEMPTS = 5;
    /** Keeps password handling within the default bcrypt input limit. */
    private const int MAX_PASSWORD_BYTES = 72;
    /** Bounds the fixed browser cookie lifetime to eight hours. */
    private const int COOKIE_MAX_AGE_SECONDS = 28800;

    /**
     * Uses database sessions and the operator's password hash.
     *
     * @param PDO $connection
     * @param string $passwordHash
     */
    public function __construct(
        /** Persists sessions and password attempt limits. */
        private PDO    $connection,
        /** Stores a hash, never a plaintext password. */
        #[SensitiveParameter]
        private string $passwordHash,
    ) {}

    /**
     * Creates an unauthenticated browser session for the CSRF-protected login form.
     *
     * @return array
     */
    public function startSession(): array
    {
        return $this->createSession(false);
    }

    /**
     * Rejects missing, expired, revoked or password-outdated sessions.
     *
     * @param string $cookie
     * @param bool $isAuthenticationRequired
     * @return AdministratorSession
     */
    public function authenticateSession(#[SensitiveParameter] string $cookie, bool $isAuthenticationRequired = true): AdministratorSession
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $cookie) !== 1) { throw new AuthenticationException('Sign in to continue.'); }
        $statement = $this->connection->prepare("UPDATE administrator_sessions SET last_seen_at = clock_timestamp()
            WHERE secret_hash = :hash AND revoked_at IS NULL AND expires_at > clock_timestamp()
                AND last_seen_at > clock_timestamp() - INTERVAL '30 minutes'
            RETURNING id, csrf_token, is_authenticated, password_version");
        $statement->execute(['hash' => hash('sha256', $cookie)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false || ($row['is_authenticated'] && !hash_equals(hash('sha256', $this->passwordHash), $row['password_version'] ?? ''))
            || ($isAuthenticationRequired && !$row['is_authenticated'])) { throw new AuthenticationException('Sign in to continue.'); }
        return new AdministratorSession((int) $row['id'], $row['csrf_token'], $row['is_authenticated']);
    }

    /**
     * Verifies the password with CSRF/rate limits and rotates the browser session.
     *
     * @param string $cookie
     * @param string $password
     * @param string $csrfToken
     * @param string $clientAddress
     * @return string
     */
    public function signIn(
        #[SensitiveParameter]
        string $cookie,
        #[SensitiveParameter]
        string $password,
        string $csrfToken,
        string $clientAddress,
    ): string {
        $session = $this->authenticateSession($cookie, false);
        $this->requireCsrf($session, $csrfToken);
        $clientHash = hash('sha256', $clientAddress);
        $limited = false;
        (new Transaction($this->connection))->execute(/**
             * Runs the operation on the transaction connection.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($clientHash, &$limited): void {
            $statement = $connection->prepare("INSERT INTO administrator_login_limits (client_hash, attempts) VALUES (:hash, 1)
                ON CONFLICT (client_hash) DO UPDATE SET
                    attempts = CASE WHEN administrator_login_limits.window_started_at < clock_timestamp() - INTERVAL '5 minutes' THEN 1 ELSE administrator_login_limits.attempts + 1 END,
                    window_started_at = CASE WHEN administrator_login_limits.window_started_at < clock_timestamp() - INTERVAL '5 minutes' THEN clock_timestamp() ELSE administrator_login_limits.window_started_at END
                RETURNING attempts");
            $statement->execute(['hash' => $clientHash]);
            $limited = (int) $statement->fetchColumn() > self::MAX_ATTEMPTS;
        });
        if ($limited) { throw new AccountException('Too many sign-in attempts. Try again in five minutes.', 429); }
        if (strlen($password) > self::MAX_PASSWORD_BYTES || !password_verify($password, $this->passwordHash)) { throw new AuthenticationException('The administrator password is incorrect.'); }
        $result = '';
        (new Transaction($this->connection))->execute(/**
             * Runs the operation on the transaction connection.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($session, $clientHash, &$result): void {
            $this->signOut($session);
            $result = $this->createSession(true)['cookie'];
            $statement = $connection->prepare('DELETE FROM administrator_login_limits WHERE client_hash = :hash');
            $statement->execute(['hash' => $clientHash]);
        });
        return $result;
    }

    /**
     * Rejects a browser mutation whose CSRF token does not match this session.
     *
     * @param AdministratorSession $session
     * @param mixed $token
     * @return void
     */
    public function requireCsrf(AdministratorSession $session, mixed $token): void
    {
        if (!(new CsrfProtection())->isTokenValid(['csrfToken' => $session->csrfToken], $token)) {
            throw new AccountException('The form expired. Reload the page and try again.', 403);
        }
    }

    /**
     * Revokes the server-side session immediately.
     *
     * @param AdministratorSession $session
     * @return void
     */
    public function signOut(AdministratorSession $session): void
    {
        $statement = $this->connection->prepare('UPDATE administrator_sessions SET revoked_at = clock_timestamp() WHERE id = :id');
        $statement->execute(['id' => $session->id]);
    }

    /**
     * Creates a host-only session cookie; the browser secret is never stored in plaintext.
     *
     * @param string $secret
     * @return string
     */
    public static function createCookie(#[SensitiveParameter] string $secret): string
    {
        return self::COOKIE_NAME . '=' . $secret . '; Path=/; Max-Age=' . self::COOKIE_MAX_AGE_SECONDS . '; Secure; HttpOnly; SameSite=Lax';
    }

    /**
     * Persists an independent session and returns its one-time cookie secret.
     *
     * @param bool $isAuthenticated
     * @return array
     */
    private function createSession(bool $isAuthenticated): array
    {
        $cookie = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $statement = $this->connection->prepare('INSERT INTO administrator_sessions (secret_hash, csrf_token, is_authenticated, password_version) VALUES (:hash, :csrf, :authenticated, :version) RETURNING id');
        $statement->bindValue('hash', hash('sha256', $cookie));
        $statement->bindValue('csrf', $csrf);
        $statement->bindValue('authenticated', $isAuthenticated, PDO::PARAM_BOOL);
        $statement->bindValue('version', $isAuthenticated ? hash('sha256', $this->passwordHash) : null);
        $statement->execute();
        return ['cookie' => $cookie, 'session' => new AdministratorSession((int) $statement->fetchColumn(), $csrf, $isAuthenticated)];
    }
}
