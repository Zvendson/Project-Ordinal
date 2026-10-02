<?php

declare(strict_types=1);

namespace Ordinal\Security;

/** Issues and validates CSRF tokens stored in a server-side browser session. */
final class CsrfProtection
{
    /** Names the private session token entry. */
    private const string SESSION_KEY = 'csrfToken';
    /** Provides 256 bits of token randomness. */
    private const int TOKEN_BYTES = 32;

    /**
     * Reuses a valid session token or generates a new random token.
     *
     * @param array $session
     * @return string
     * @throws \Random\RandomException
     */
    public function issueToken(array &$session): string
    {
        $token = $session[self::SESSION_KEY] ?? null;
        if (!$this->isTokenWellFormed($token)) {
            $session[self::SESSION_KEY] = bin2hex(random_bytes(self::TOKEN_BYTES));
        }

        return $session[self::SESSION_KEY];
    }

    /**
     * Compares the submitted token with the current session using constant-time comparison.
     *
     * @param array $session
     * @param mixed $submittedToken
     * @return bool
     */
    public function isTokenValid(array $session, mixed $submittedToken): bool
    {
        $token = $session[self::SESSION_KEY] ?? null;
        return $this->isTokenWellFormed($token) && is_string($submittedToken)
            && hash_equals($token, $submittedToken);
    }

    /**
     * Requires the expected length and hexadecimal format for a stored token.
     *
     * @param mixed $token
     * @return bool
     */
    private function isTokenWellFormed(mixed $token): bool
    {
        return is_string($token) && strlen($token) === self::TOKEN_BYTES * 2
            && preg_match('/^[a-f0-9]+$/', $token) === 1;
    }
}
