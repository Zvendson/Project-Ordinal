<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Model;

use Ordinal\Model\BuildNumber;
use Ordinal\Service\AccountException;
use PHPUnit\Framework\TestCase;

/** Verifies canonical whole-number form input without coercion or loss of uint32 edges. */
final class BuildNumberTest extends TestCase
{
    /**
     * Accepts zero and uint32 maximum and rejects signed/fractional/exponent/overflow input.
     *
     * @return void
     */
    public function testParsesOnlyCanonicalWholeNumbers(): void
    {
        self::assertSame(0, BuildNumber::parse('0'));
        self::assertSame(4294967295, BuildNumber::parse('4294967295'));
        foreach (['', '-1', '+1', '1.5', '1e2', '4294967296', '9223372036854775808', ' 1', '01', 'NaN'] as $input) {
            try { BuildNumber::parse($input); self::fail('Invalid number accepted.'); }
            catch (AccountException $exception) { self::assertSame(400, $exception->statusCode); }
        }
    }
}
