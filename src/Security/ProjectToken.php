<?php

declare(strict_types=1);

namespace Ordinal\Security;

use SensitiveParameter;

/** Formats independent named project credentials while preserving issued legacy token compatibility. */
final class ProjectToken
{
    /**
     * Generates a 256-bit random token secret.
     *
     * @return string
     */
    public static function createSecret(): string { return OpaqueToken::createSecret(); }

    /**
     * Encodes the stable token identity and one-time secret.
     *
     * @param int $id
     * @param string $secret
     * @return string
     */
    public static function createToken(int $id, #[SensitiveParameter] string $secret): string
    {
        return OpaqueToken::createToken('project', $id, $secret);
    }

    /**
     * Accepts project tokens and already-issued automation tokens during migration.
     *
     * @param ?string $token
     * @return array
     */
    public static function parseToken(#[SensitiveParameter] ?string $token): array
    {
        return OpaqueToken::parseToken(str_starts_with($token ?? '', 'automation.') ? 'automation' : 'project', $token);
    }
}
