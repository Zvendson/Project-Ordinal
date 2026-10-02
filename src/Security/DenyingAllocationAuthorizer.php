<?php

declare(strict_types=1);

namespace Ordinal\Security;

use Ordinal\Model\AllocationCaller;
use SensitiveParameter;

/** Rejects every allocation until credential verification is implemented. */
final class DenyingAllocationAuthorizer extends AllocationAuthorizer
{
    /**
     * Rejects credentials rather than granting temporary access to protected projects.
     *
     * @param int $projectId
     * @param ?string $bearerToken
     * @return AllocationCaller
     * @throws AuthenticationException
     */
    public function authorizeAllocation(
        int     $projectId,
        #[SensitiveParameter]
        ?string $bearerToken,
    ): AllocationCaller {
        throw new AuthenticationException();
    }
}
