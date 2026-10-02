<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Security;

use Ordinal\Security\CsrfProtection;
use PHPUnit\Framework\TestCase;

/** Verifies random tokens tied to one server-side browser session. */
final class CsrfProtectionTest extends TestCase
{
    /**
     * Preserves a session token and separates different sessions.
     *
     * @return void
     */
    public function testIssuesSessionBoundTokens(): void
    {
        $firstSession  = [];
        $secondSession = [];
        $protection    = new CsrfProtection();
        $firstToken    = $protection->issueToken($firstSession);
        $secondToken   = $protection->issueToken($secondSession);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $firstToken);
        self::assertSame($firstToken, $protection->issueToken($firstSession));
        self::assertNotSame($firstToken, $secondToken);
        self::assertTrue($protection->isTokenValid($firstSession, $firstToken));
        self::assertFalse($protection->isTokenValid($secondSession, $firstToken));
    }

    /**
     * Rejects missing, malformed, or untrusted token values.
     *
     * @return void
     */
    public function testRejectsInvalidTokens(): void
    {
        $session    = [];
        $protection = new CsrfProtection();
        self::assertFalse($protection->isTokenValid($session, ''));
        $protection->issueToken($session);
        foreach ([null, '', [], 123, str_repeat('0', 64)] as $token) {
            self::assertFalse($protection->isTokenValid($session, $token));
        }
    }

    /**
     * Replaces damaged session tokens rather than accepting a predictable value.
     *
     * @return void
     */
    public function testReplacesMalformedSessionToken(): void
    {
        $session    = ['csrfToken' => 'broken'];
        $protection = new CsrfProtection();
        self::assertFalse($protection->isTokenValid($session, 'broken'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $protection->issueToken($session));
    }
}
