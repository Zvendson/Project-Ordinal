<?php

declare(strict_types=1);

namespace Ordinal\Model;

use SensitiveParameter;

/** Holds validated operator configuration without putting secrets in the database. */
final readonly class SecurityConfiguration
{
    /**
     * Keeps the encryption key and registered client secrets outside persistent application records.
     *
     * @param string $encryptionKey
     * @param string $bootstrapConnection
     * @param string $bootstrapUserId
     * @param array $connections
     */
    public function __construct(
        /** Contains the hex-encoded encryption key. */
        #[SensitiveParameter]
        public string $encryptionKey,
        /** Names the bootstrap administrator's registered connection. */
        public string $bootstrapConnection,
        /** Identifies the configured administrator by immutable provider user ID. */
        public string $bootstrapUserId,
        /** Maps operator-managed registration references to validated client configuration. */
        #[SensitiveParameter]
        public array  $connections,
    ) {}
}
