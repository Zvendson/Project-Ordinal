<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Security;

use Ordinal\Security\AuthenticationException;
use Ordinal\Security\DeviceToken;
use PHPUnit\Framework\TestCase;

/** Verifies canonical device lookup IDs and opaque secret parsing. */
final class DeviceTokenTest extends TestCase
{
    /**
     * Verifies a generated secret survives canonical token parsing.
     *
     * @return void
     */
    public function testCreatesAndParsesOpaqueToken(): void
    {
        $secret = DeviceToken::createSecret();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $secret);
        self::assertNotSame($secret, DeviceToken::createSecret());
        $token = DeviceToken::createToken(42, $secret);
        self::assertSame(['id' => 42, 'secret' => $secret], DeviceToken::parseToken($token));
    }

    /**
     * Rejects ambiguous IDs, other token categories, and malformed secrets.
     *
     * @return void
     */
    public function testRejectsMalformedTokens(): void
    {
        foreach (['', 'device.0.' . str_repeat('a', 64), 'device.01.' . str_repeat('a', 64), 'automation.1.' . str_repeat('a', 64), 'device.' . PHP_INT_MAX . '0.' . str_repeat('a', 64), 'device.1.' . str_repeat('A', 64), 'device.1.' . str_repeat('a', 63)] as $token) {
            try {
                DeviceToken::parseToken($token);
                self::fail('Malformed credential was accepted.');
            } catch (AuthenticationException) {
                self::assertTrue(true);
            }
        }
    }
}
