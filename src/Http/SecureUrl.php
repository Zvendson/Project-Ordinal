<?php

declare(strict_types=1);

namespace Ordinal\Http;

use InvalidArgumentException;

/** Validates build service endpoints before bearer credentials can be sent. */
final class SecureUrl
{
    /**
     * Rejects non-HTTPS URLs, embedded credentials and fragments.
     *
     * @param string $url
     * @return void
     */
    public static function assertSecureUrl(string $url): void
    {
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || $parts === false
            || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Build endpoint URLs must use HTTPS without credentials or fragments.');
        }
    }
}
