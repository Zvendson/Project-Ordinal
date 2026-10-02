<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Security;

use Ordinal\Security\AuthenticationException;
use Ordinal\Security\ProjectToken;
use PHPUnit\Framework\TestCase;

/** Verifies that project secrets have a distinct strict lookup namespace. */
final class ProjectTokenTest extends TestCase
{
    /**
     * Preserves lookup ID and cryptographic secret in the project namespace.
     *
     * @return void
     */
    public function testCreatesAndParsesProjectToken(): void
    {
        $secret = ProjectToken::createSecret();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $secret);
        self::assertNotSame($secret, ProjectToken::createSecret());
        self::assertSame(['id' => 9, 'secret' => $secret], ProjectToken::parseToken(ProjectToken::createToken(9, $secret)));
    }

    /**
     * Rejects device credentials and malformed project credentials.
     *
     * @return void
     */
    public function testRejectsOtherCredentialKinds(): void
    {
        foreach (['device.9.' . str_repeat('a', 64), 'project.09.' . str_repeat('a', 64), 'project.9.short', 'project.9.' . str_repeat('A', 64)] as $token) {
            try {
                ProjectToken::parseToken($token);
                self::fail('Invalid project credential was accepted.');
            } catch (AuthenticationException) {
                self::assertTrue(true);
            }
        }
    }
}
