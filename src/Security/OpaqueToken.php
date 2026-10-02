<?php

declare(strict_types=1);

namespace Ordinal\Security;

use InvalidArgumentException;
use SensitiveParameter;

/** Shares cryptographic secrets and strict stable-ID encoding across credential categories. */
final class OpaqueToken
{
    /** Provides 256 bits of independent secret entropy. */
    private const int SECRET_BYTES = 32;

    /**
     * Generates a secret without encoding identity or permissions.
     *
     * @return string
     */
    public static function createSecret(): string
    {
        return bin2hex(random_bytes(self::SECRET_BYTES));
    }

    /**
     * Formats one controlled credential category.
     *
     * @param string $kind
     * @param int $id
     * @param string $secret
     * @return string
     */
    public static function createToken(string $kind, int $id, #[SensitiveParameter] string $secret): string
    {
        if (!in_array($kind, ['project', 'automation'], true) || $id < 1 || preg_match('/^[a-f0-9]{64}$/D', $secret) !== 1) {
            throw new InvalidArgumentException('Invalid credential format.');
        }
        return $kind . '.' . $id . '.' . $secret;
    }

    /**
     * Rejects wrong categories, ambiguous IDs, overflow, and malformed secrets.
     *
     * @param string $kind
     * @param ?string $token
     * @return array
     */
    public static function parseToken(string $kind, #[SensitiveParameter] ?string $token): array
    {
        if (!in_array($kind, ['project', 'automation'], true)
            || preg_match('/^' . preg_quote($kind, '/') . '\.([1-9][0-9]*)\.([a-f0-9]{64})$/D', $token ?? '', $matches) !== 1
            || filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new AuthenticationException('Authentication is required or invalid.');
        }
        return ['id' => (int) $matches[1], 'secret' => $matches[2]];
    }
}
