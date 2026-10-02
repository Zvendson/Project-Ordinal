<?php

declare(strict_types=1);

namespace Ordinal\Model;

use DateTimeImmutable;

/** Represents an authenticated browser identity without retaining its cookie secret. */
final readonly class BrowserSession
{
    /**
     * Carries validated session and provider-qualified identity data.
     *
     * @param int $id
     * @param int $userId
     * @param int $providerConnectionId
     * @param string $providerUserId
     * @param string $displayName
     * @param string $csrfToken
     * @param ?DateTimeImmutable $providerReauthenticatedAt
     * @param DateTimeImmutable $expiresAt
     */
    public function __construct(
        /** Identifies the persisted session. */
        public int                $id,
        /** Identifies the local account. */
        public int                $userId,
        /** Identifies the configured provider instance. */
        public int                $providerConnectionId,
        /** Preserves the immutable external identity. */
        public string             $providerUserId,
        /** Holds the current readable name. */
        public string             $displayName,
        /** Binds browser mutations to this session. */
        public string             $csrfToken,
        /** Records provider sign-in, never credential refresh or ordinary activity. */
        public ?DateTimeImmutable $providerReauthenticatedAt,
        /** Records the fixed absolute expiration. */
        public DateTimeImmutable  $expiresAt,
    ) {}
}
