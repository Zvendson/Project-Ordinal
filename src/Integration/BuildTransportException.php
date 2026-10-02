<?php

declare(strict_types=1);

namespace Ordinal\Integration;

use RuntimeException;

/** Represents a retryable connection/transport failure without upstream diagnostics or secrets. */
final class BuildTransportException extends RuntimeException
{
    /** Creates a fixed public diagnostic without retaining curl errors or credentials. */
    public function __construct()
    {
        parent::__construct('The build-number request could not be completed.');
    }
}
