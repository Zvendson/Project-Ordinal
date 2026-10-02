<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Http;

use InvalidArgumentException;
use Ordinal\Http\SecureUrl;
use PHPUnit\Framework\TestCase;

/** Keeps build helper credentials on a secure endpoint without provider configuration. */
final class SecureUrlTest extends TestCase
{
    /**
     * Rejects insecure or credential-bearing endpoints before a transport can use them.
     *
     * @return void
     */
    public function testValidatesHttpsEndpoints(): void
    {
        SecureUrl::assertSecureUrl('https://ordinal.example/api/projects/1/build-numbers');
        foreach (['http://ordinal.example/', 'https://user:password@ordinal.example/', 'https://ordinal.example/#fragment', 'invalid'] as $url) {
            try { SecureUrl::assertSecureUrl($url); self::fail('Unsafe URL accepted.'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
