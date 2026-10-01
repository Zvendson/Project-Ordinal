<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Model;

use InvalidArgumentException;
use Ordinal\Model\RepositoryPermissions;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the behavior of RepositoryPermissions.
 */
final class RepositoryPermissionsTest extends TestCase
{
    /**
     * Verifies: denies access by default.
     *
     * @return void
     */
    public function testDeniesAccessByDefault(): void
    {
        $permissions = new RepositoryPermissions();

        self::assertFalse($permissions->canAllocateBuildNumber);
        self::assertFalse($permissions->canAdministerProject);
    }

    /**
     * Verifies: allows allocation without administration.
     *
     * @return void
     */
    public function testAllowsAllocationWithoutAdministration(): void
    {
        $permissions = new RepositoryPermissions(true, false);

        self::assertTrue($permissions->canAllocateBuildNumber);
        self::assertFalse($permissions->canAdministerProject);
    }

    /**
     * Verifies: allows administration with allocation.
     *
     * @return void
     */
    public function testAllowsAdministrationWithAllocation(): void
    {
        $permissions = new RepositoryPermissions(true, true);

        self::assertTrue($permissions->canAllocateBuildNumber);
        self::assertTrue($permissions->canAdministerProject);
    }

    /**
     * Verifies: rejects administration without allocation.
     *
     * @return void
     */
    public function testRejectsAdministrationWithoutAllocation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RepositoryPermissions(false, true);
    }
}
