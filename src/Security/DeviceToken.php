<?php

declare(strict_types=1);

namespace Ordinal\Security;

use InvalidArgumentException;
use SensitiveParameter;

/** Encodes a stable lookup ID and a separate cryptographically random device secret. */
final class DeviceToken
{
    /** Provides 256 bits of secret entropy. */
    private const int SECRET_BYTES = 32;

    /**
     * Generates a new opaque secret independently of device metadata.
     *
     * @return string
     */
    public static function createSecret(): string
    {
        return bin2hex(random_bytes(self::SECRET_BYTES));
    }

    /**
     * Formats a credential for the Bearer header.
     *
     * @param int $id
     * @param string $secret
     * @return string
     */
    public static function createToken(int $id, #[SensitiveParameter] string $secret): string
    {
        if ($id < 1 || preg_match('/^[a-f0-9]{64}$/D', $secret) !== 1) {
            throw new InvalidArgumentException('Invalid device credential format.');
        }
        return 'device.' . $id . '.' . $secret;
    }

    /**
     * Parses canonical lookup data or rejects an invalid credential.
     *
     * @param ?string $token
     * @return array
     * @throws AuthenticationException
     */
    public static function parseToken(#[SensitiveParameter] ?string $token): array
    {
        if (preg_match('/^device\.([1-9][0-9]*)\.([a-f0-9]{64})$/D', $token ?? '', $matches) !== 1
            || filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new AuthenticationException('Device authentication is required or invalid.');
        }
        return ['id' => (int) $matches[1], 'secret' => $matches[2]];
    }
}
