<?php

declare(strict_types=1);

namespace Ordinal\Model;

/**
 * Identifies a provider user by connection and immutable user ID.
 */
final readonly class ProviderIdentity
{
    /**
     * Creates an immutable provider-qualified user identity.
     *
     * @param int $providerConnectionId
     * @param string $providerUserId
     * @param string $userName
     * @param string $displayName
     */
    public function __construct(
        /** Identifies the configured provider connection. */
        public int    $providerConnectionId,
        /** Stores the immutable user ID assigned by the provider. */
        public string $providerUserId,
        /** Stores the current username used for display or provider lookup. */
        public string $userName,
        /** Stores the readable user display name. */
        public string $displayName,
    ) {}
}
