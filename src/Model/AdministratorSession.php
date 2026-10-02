<?php

declare(strict_types=1);

namespace Ordinal\Model;

/** Carries validated local administrator session data without its cookie secret. */
final readonly class AdministratorSession
{
    /**
     * Creates the minimal browser management context.
     *
     * @param int $id
     * @param string $csrfToken
     * @param bool $isAuthenticated
     */
    public function __construct(
        /** Identifies the persisted browser session. */
        public int    $id,
        /** Binds form submissions to this browser. */
        public string $csrfToken,
        /** Records whether the administrator password was verified. */
        public bool   $isAuthenticated,
    ) {}
}
