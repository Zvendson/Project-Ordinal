<?php

declare(strict_types=1);

namespace Ordinal\Security;

use SensitiveParameter;

/** Encodes opaque automation credentials in their own stable lookup namespace. */
final class AutomationToken
{
    /**
     * Generates a fresh automation secret.
     *
     * @return string
     */
    public static function createSecret(): string
    {
        return OpaqueToken::createSecret();
    }

    /**
     * Formats an automation Bearer credential.
     *
     * @param int $id
     * @param string $secret
     * @return string
     */
    public static function createToken(int $id, #[SensitiveParameter] string $secret): string
    {
        return OpaqueToken::createToken('automation', $id, $secret);
    }

    /**
     * Parses only canonical automation credentials.
     *
     * @param ?string $token
     * @return array
     */
    public static function parseToken(#[SensitiveParameter] ?string $token): array
    {
        return OpaqueToken::parseToken('automation', $token);
    }
}
