<?php

declare(strict_types=1);

namespace Ordinal\Service;

use DateTimeImmutable;
use Ordinal\Model\BrowserSession;
use Ordinal\Repository\BrowserSessionRepository;
use Ordinal\Security\AuthenticationException;
use SensitiveParameter;

/** Issues opaque hashed sessions and enforces server-side validity and CSRF boundaries. */
final readonly class BrowserSessionService
{
    /** Names a host-only Secure cookie with no Domain attribute. */
    public const string COOKIE_NAME = '__Host-ordinal-session';
    /** Provides 256 bits of randomness for session and CSRF credentials. */
    public const int SECRET_BYTES = 32;
    /** Bounds provider sign-in freshness for sensitive administrative changes. */
    private const int REAUTHENTICATION_SECONDS = 300;

    /**
     * Uses the session repository for expiration/revocation checks.
     *
     * @param BrowserSessionRepository $repository
     */
    public function __construct(
        /** Stores only hashes of browser session credentials. */
        private BrowserSessionRepository $repository,
    ) {}

    /**
     * Creates a fresh session secret and independent CSRF token.
     *
     * @param int $userId
     * @return string
     */
    public function createSession(int $userId): string
    {
        $secret = bin2hex(random_bytes(self::SECRET_BYTES));
        $this->repository->createSession($userId, hash('sha256', $secret), bin2hex(random_bytes(self::SECRET_BYTES)));
        return $secret;
    }

    /**
     * Validates the browser cookie against the database and updates activity only.
     *
     * @param string $secret
     * @return BrowserSession
     * @throws AuthenticationException
     */
    public function authenticate(
        #[SensitiveParameter]
        string $secret,
    ): BrowserSession
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $secret) !== 1) {
            throw new AuthenticationException('Authentication is required or invalid.');
        }
        $row = $this->repository->authenticate(hash('sha256', $secret));
        if ($row === null || !hash_equals($row['secret_hash'], hash('sha256', $secret))) {
            throw new AuthenticationException('Authentication is required or invalid.');
        }
        return $this->createSessionModel($row);
    }

    /**
     * Rechecks current validity for protected operations and rejects stale session snapshots.
     *
     * @param BrowserSession $session
     * @param bool $isLocked
     * @return BrowserSession
     * @throws AuthenticationException
     */
    public function requireActiveSession(BrowserSession $session, bool $isLocked = false): BrowserSession
    {
        $row = $this->repository->findActiveSession($session->id, $isLocked);
        if ($row === null || (int) $row['user_id'] !== $session->userId || !hash_equals($row['csrf_token'], $session->csrfToken)) {
            throw new AuthenticationException('Authentication is required or invalid.');
        }
        return $this->createSessionModel($row);
    }

    /**
     * Compares submitted form state in constant time after current validity is checked.
     *
     * @param BrowserSession $session
     * @param mixed $submittedToken
     * @return void
     * @throws AccountException
     */
    public function requireCsrfToken(
        BrowserSession $session,
        #[SensitiveParameter]
        mixed          $submittedToken,
    ): void
    {
        $session = $this->requireActiveSession($session);
        if (!is_string($submittedToken) || !hash_equals($session->csrfToken, $submittedToken)) {
            throw new AccountException('The form expired or is invalid. Reload the page and try again.');
        }
    }

    /**
     * Revokes an authenticated session without deleting its historical record.
     *
     * @param BrowserSession $session
     * @return void
     */
    public function revokeSession(BrowserSession $session): void
    {
        $this->requireActiveSession($session);
        $this->repository->revokeSession($session->id);
    }

    /**
     * Requires the persisted provider sign-in to be within five minutes, excluding refresh/activity.
     *
     * @param BrowserSession $session
     * @return void
     */
    public function requireRecentProviderAuthentication(BrowserSession $session): void
    {
        $current = $this->requireActiveSession($session);
        $at = $current->providerReauthenticatedAt?->getTimestamp();
        $now = time();
        if ($at === null || $at > $now || $at < $now - self::REAUTHENTICATION_SECONDS) {
            throw new AccountException('Sign in again before performing this sensitive action.');
        }
    }

    /**
     * Rotates browser credentials after a matching provider sign-in; absolute lifetime is unchanged.
     *
     * @param BrowserSession $session
     * @return string
     */
    public function reauthenticateSession(BrowserSession $session): string
    {
        $this->requireActiveSession($session, true);
        $secret = bin2hex(random_bytes(self::SECRET_BYTES));
        $this->repository->reauthenticateSession($session->id, hash('sha256', $secret), bin2hex(random_bytes(self::SECRET_BYTES)));
        return $secret;
    }

    /**
     * Builds a host-only secure session cookie without an invented expiry extension.
     *
     * @param string $secret
     * @return string
     */
    public static function createCookie(
        #[SensitiveParameter]
        string $secret,
    ): string
    {
        return self::COOKIE_NAME . '=' . $secret . '; Path=/; Secure; HttpOnly; SameSite=Lax';
    }

    /**
     * Deletes the same host-only secure cookie used for sign-in.
     *
     * @return string
     */
    public static function createExpiredCookie(): string
    {
        return self::COOKIE_NAME . '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Lax';
    }

    /**
     * Converts validated persistence fields to a typed browser session.
     *
     * @param array $row
     * @return BrowserSession
     */
    private function createSessionModel(
        #[SensitiveParameter]
        array $row,
    ): BrowserSession
    {
        return new BrowserSession((int) $row['id'], (int) $row['user_id'], (int) $row['provider_connection_id'], $row['provider_user_id'], $row['display_name'], $row['csrf_token'],
            $row['provider_reauthenticated_at'] === null ? null : new DateTimeImmutable($row['provider_reauthenticated_at']), new DateTimeImmutable($row['expires_at']));
    }
}
