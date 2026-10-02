<?php

declare(strict_types=1);

namespace Ordinal\Service;

use RuntimeException;

/** Signals a stable allocation failure code for later API mapping. */
final class AllocationException extends RuntimeException
{
    /** Identifies an unavailable project. */
    public const string PROJECT_NOT_FOUND = 'PROJECT_NOT_FOUND';
    /** Identifies anonymous access that current project policy no longer permits. */
    public const string INVALID_AUTHENTICATION = 'INVALID_AUTHENTICATION';
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
