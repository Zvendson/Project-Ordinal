<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Model;

use InvalidArgumentException;
use Ordinal\Model\AllocationCaller;
use PHPUnit\Framework\TestCase;

/** Verifies that a verified caller cannot combine incompatible actor categories. */
final class AllocationCallerTest extends TestCase
{
    /**
     * Distinguishes users, automation tokens, and anonymous callers.
     *
     * @return void
     */
    public function testIdentifiesCallerCategories(): void
    {
        self::assertSame('anonymous', (new AllocationCaller())->getKind());
        self::assertSame('user', (new AllocationCaller(userId: 1))->getKind());
        self::assertSame('automation', (new AllocationCaller(automationTokenId: 1))->getKind());
    }

    /**
     * Rejects conflicting, missing-owner, and invalid actor IDs.
     *
     * @return void
     */
    public function testRejectsInvalidRelationships(): void
    {
        foreach ([[1, 1, null], [null, null, 1], [0, null, null], [null, -1, null], [1, null, 0]] as $ids) {
            try {
                new AllocationCaller(...$ids);
                self::fail('Invalid actor relationships must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
