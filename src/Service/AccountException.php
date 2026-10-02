<?php

declare(strict_types=1);

namespace Ordinal\Service;

use RuntimeException;

/** Carries a safe browser-facing validation, authorization, or conflict failure. */
final class AccountException extends RuntimeException
{
    /**
     * Stores a fixed public message and corresponding HTTP status.
     *
     * @param string $message
     * @param int $statusCode
     */
    public function __construct(
        string              $message,
        /** Contains the stable browser-facing HTTP failure status. */
        public readonly int $statusCode = 403,
    )
    {
        parent::__construct($message);
    }
}
