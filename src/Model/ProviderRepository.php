<?php

declare(strict_types=1);

namespace Ordinal\Model;

/**
 * Identifies a provider repository independently of its mutable name.
 */
final readonly class ProviderRepository
{
    /**
     * Creates an immutable provider-qualified repository identity.
     *
     * @param int $providerConnectionId
     * @param string $providerRepositoryId
     * @param string $name
     */
    public function __construct(
        /** Identifies the configured provider connection. */
        public int    $providerConnectionId,
        /** Stores the immutable repository ID assigned by the provider. */
        public string $providerRepositoryId,
        /** Stores the readable repository name. */
        public string $name,
    ) {}
}
