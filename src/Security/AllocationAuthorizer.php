<?php

declare(strict_types=1);

namespace Ordinal\Security;

use Ordinal\Model\AllocationCaller;
use SensitiveParameter;

/** Defines the authorization boundary required before any allocation or replay. */
abstract class AllocationAuthorizer
{
    /**
     * Verifies the credential/current permission and returns stable verified caller IDs.
     *
     * @param int $projectId
     * @param ?string $bearerToken
     * @return AllocationCaller
     * @throws AuthenticationException
     */
    abstract public function authorizeAllocation(
        int     $projectId,
        #[SensitiveParameter]
        ?string $bearerToken,
    ): AllocationCaller;
}
