<?php

declare(strict_types=1);

namespace Ordinal\Security;

use SensitiveParameter;

/** Encodes a stable lookup ID and a separate cryptographically random device secret. */
final class DeviceToken
{
    /**
     * Generates a new opaque secret independently of device metadata.
     *
     * @return string
     */
    public static function createSecret(): string
    {
        return OpaqueToken::createSecret();
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
        return OpaqueToken::createToken('device', $id, $secret);
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
        return OpaqueToken::parseToken('device', $token);
    }
}
