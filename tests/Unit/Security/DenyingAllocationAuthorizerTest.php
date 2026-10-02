<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Security;

use Ordinal\Security\AuthenticationException;
use Ordinal\Security\DenyingAllocationAuthorizer;
use PHPUnit\Framework\TestCase;

/** Verifies that unavailable credentials cannot authorize allocations. */
final class DenyingAllocationAuthorizerTest extends TestCase
{
    /**
     * Rejects any supplied token without granting anonymous or authenticated access.
     *
     * @return void
     */
    public function testRejectsAllCredentials(): void
    {
        $this->expectException(AuthenticationException::class);
        (new DenyingAllocationAuthorizer())->authorizeAllocation(1, 'test-secret');
    }
}
