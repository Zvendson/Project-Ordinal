<?php

declare(strict_types=1);

namespace Ordinal\Model;

use SensitiveParameter;

/**
 * Carries internal database settings; credentials must not be logged or exposed.
 */
final readonly class DatabaseConfiguration
{
    /**
     * Creates an immutable snapshot of the database connection settings.
     *
     * @param string $dsn
     * @param string $userName
     * @param string $password
     */
    public function __construct(
        /** Stores the PostgreSQL connection string as internal configuration. */
        public string $dsn,
        /** Names the PostgreSQL login used by the application. */
        public string $userName,
        /** Contains the database login secret; never log or expose it. */
        #[SensitiveParameter]
        public string $password,
    ) {}
}
