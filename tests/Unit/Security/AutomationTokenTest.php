<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Security;

use Ordinal\Security\AuthenticationException;
use Ordinal\Security\AutomationToken;
use PHPUnit\Framework\TestCase;

/** Verifies that automation secrets have a distinct strict lookup namespace. */
final class AutomationTokenTest extends TestCase
{
    /**
     * Preserves lookup ID and cryptographic secret in the automation namespace.
     *
     * @return void
     */
    public function testCreatesAndParsesAutomationToken(): void
    {
        $secret = AutomationToken::createSecret();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $secret);
        self::assertNotSame($secret, AutomationToken::createSecret());
        self::assertSame(['id' => 9, 'secret' => $secret], AutomationToken::parseToken(AutomationToken::createToken(9, $secret)));
    }

    /**
     * Rejects device credentials and malformed automation credentials.
     *
     * @return void
     */
    public function testRejectsOtherCredentialKinds(): void
    {
        foreach (['device.9.' . str_repeat('a', 64), 'automation.09.' . str_repeat('a', 64), 'automation.9.short', 'automation.9.' . str_repeat('A', 64)] as $token) {
            try {
                AutomationToken::parseToken($token);
                self::fail('Invalid automation credential was accepted.');
            } catch (AuthenticationException) {
                self::assertTrue(true);
            }
        }
    }
}
