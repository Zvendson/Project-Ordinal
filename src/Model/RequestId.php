<?php

declare(strict_types=1);

namespace Ordinal\Model;

/** Shares UUID-v4 identity generation and validation between the API and build integrations. */
final class RequestId
{
    /** Requires standard UUID version 4 and RFC variant bits. */
    private const string PATTERN = '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD';

    /**
     * Checks the exact agreed request-ID format.
     *
     * @param string $value
     * @return bool
     */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Creates a cryptographically random UUID v4 for a genuinely new build attempt.
     *
     * @return string
     */
    public static function create(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
