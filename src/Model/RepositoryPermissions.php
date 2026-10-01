<?php

declare(strict_types=1);

namespace Ordinal\Model;

use InvalidArgumentException;

/**
 * Represents verified allocation and project administration permissions.
 */
final readonly class RepositoryPermissions
{
    /**
     * Creates permission flags, rejecting administration without allocation permission.
     *
     * @param bool $canAllocateBuildNumber
     * @param bool $canAdministerProject
     * @throws \InvalidArgumentException
     */
    public function __construct(
        /** Indicates verified permission to allocate build numbers. */
        public bool $canAllocateBuildNumber = false,
        /** Indicates verified permission to administer the linked project. */
        public bool $canAdministerProject   = false,
    ) {
        if ($canAdministerProject && !$canAllocateBuildNumber) {
            throw new InvalidArgumentException('Project administration requires allocation permission.');
        }
    }
}
