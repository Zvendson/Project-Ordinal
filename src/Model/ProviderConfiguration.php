<?php

declare(strict_types=1);

namespace Ordinal\Model;

use InvalidArgumentException;
use SensitiveParameter;

/** Holds the registered OAuth client configuration; persistence belongs to a later step. */
final readonly class ProviderConfiguration
{
    /**
     * Validates registered client credentials and its HTTPS callback URL.
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param string $redirectUri
     * @throws InvalidArgumentException
     */
    public function __construct(
        /** Identifies the registered provider app. */
        public string $clientId,
        /** Contains the app secret and must never be logged or displayed. */
        #[SensitiveParameter]
        public string $clientSecret,
        /** Stores the exact registered callback URL. */
        public string $redirectUri,
    ) {
        if (trim($clientId) === '' || trim($clientSecret) === '') {
            throw new InvalidArgumentException('Provider client credentials must be configured.');
        }
        self::assertSecureUrl($redirectUri);
    }

    /**
     * Rejects non-HTTPS URLs, embedded credentials, and fragments.
     *
     * @param string $url
     * @return void
     * @throws InvalidArgumentException
     */
    public static function assertSecureUrl(string $url): void
    {
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || $parts === false
            || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Provider URLs must use HTTPS without credentials or fragments.');
        }
    }
}
