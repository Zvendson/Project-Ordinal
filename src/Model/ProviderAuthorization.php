<?php

declare(strict_types=1);

namespace Ordinal\Model;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * Carries reusable provider tokens and expiry values; tokens require protected storage.
 */
final readonly class ProviderAuthorization
{
    /**
     * Creates an immutable snapshot of provider credentials and their expiry values.
     *
     * @param int $providerConnectionId
     * @param string $accessToken
     * @param ?string $refreshToken
     * @param ?DateTimeImmutable $expiresAt
     * @param ?DateTimeImmutable $refreshExpiresAt
     */
    public function __construct(
        /** Identifies the configured provider connection. */
        public int                $providerConnectionId,
        /** Contains a reusable provider secret; never expose it in logs or API output. */
        #[SensitiveParameter]
        public string             $accessToken,
        /** Contains the optional provider refresh secret. */
        #[SensitiveParameter]
        public ?string            $refreshToken,
        /** Records the access-token expiry, or null when no expiry is supplied. */
        public ?DateTimeImmutable $expiresAt,
        /** Records the refresh-token expiry, or null when no expiry is supplied. */
        public ?DateTimeImmutable $refreshExpiresAt = null,
    ) {}
}
