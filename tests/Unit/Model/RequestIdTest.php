<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Model;

use Ordinal\Model\RequestId;
use PHPUnit\Framework\TestCase;

/** Verifies shared UUID-v4 generation and API/build-helper validation. */
final class RequestIdTest extends TestCase
{
    /**
     * Produces distinct standard UUID-v4 values and rejects other versions/variants.
     *
     * @return void
     */
    public function testCreatesAndValidatesRequestIds(): void
    {
        $id = RequestId::create();
        self::assertTrue(RequestId::isValid($id));
        self::assertTrue(RequestId::isValid(strtoupper($id)));
        self::assertNotSame($id, RequestId::create());
        foreach (['', '11111111-1111-1111-8111-111111111111', '11111111-1111-4111-1111-111111111111'] as $invalid) {
            self::assertFalse(RequestId::isValid($invalid));
        }
    }
}
