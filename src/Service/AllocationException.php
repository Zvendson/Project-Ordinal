<?php

declare(strict_types=1);

namespace Ordinal\Service;

use RuntimeException;

/** Signals a stable allocation failure code for later API mapping. */
final class AllocationException extends RuntimeException
{
    /** Identifies an unavailable project. */
    public const string PROJECT_NOT_FOUND = 'PROJECT_NOT_FOUND';
    /** Identifies authentication that current project policy requires or no longer accepts. */
    public const string INVALID_AUTHENTICATION = 'INVALID_AUTHENTICATION';
    /** Identifies insufficient permission or a credential scoped to another project. */
    public const string ACCESS_DENIED = 'ACCESS_DENIED';
    /** Identifies temporary unavailability of the build service. */
    public const string SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    /** Identifies a counter with no remaining uint32 numbers. */
    public const string BUILD_COUNTER_EXHAUSTED = 'BUILD_COUNTER_EXHAUSTED';

    /**
     * Stores a public failure code without database or credential details.
     *
     * @param string $errorCode
     */
    public function __construct(
        /** Supplies the stable error code for the API layer. */
        public readonly string $errorCode,
    ) {
        parent::__construct($errorCode);
    }
}
