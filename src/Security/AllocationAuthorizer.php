<?php

declare(strict_types=1);

namespace Ordinal\Security;

use SensitiveParameter;

/** Defines the authorization boundary required before any allocation or replay. */
abstract class AllocationAuthorizer
{
    /**
     * Verifies the supplied credential and current allocation permission for a project.
     *
     * @param int $projectId
     * @param ?string $bearerToken
     * @return void
     * @throws AuthenticationException
     */
    abstract public function authorizeAllocation(
        int     $projectId,
        #[SensitiveParameter]
        ?string $bearerToken,
    ): void;
}
