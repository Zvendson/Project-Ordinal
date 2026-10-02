<?php

declare(strict_types=1);

namespace Ordinal\Security;

use RuntimeException;

/** Signals missing or invalid authentication without exposing credential details. */
final class AuthenticationException extends RuntimeException
{
    /**
     * Carries an optional fixed failure reason without credentials or upstream details.
     *
     * @param string $message
     * @param ?string $reason
     */
    public function __construct(
        string                  $message = '',
        /** Classifies local expiry/revocation for secret-free audit records. */
        public readonly ?string $reason  = null,
    ) {
        parent::__construct($message);
    }
}
