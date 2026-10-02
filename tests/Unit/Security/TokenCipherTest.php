<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Security;

use InvalidArgumentException;
use Ordinal\Security\TokenCipher;
use Ordinal\Security\AuthenticationException;
use PHPUnit\Framework\TestCase;

/** Verifies reusable tokens are authenticated, randomized, and bound to their record context. */
final class TokenCipherTest extends TestCase
{
    /**
     * Verifies round trips use different ciphertext for the same plaintext.
     *
     * @return void
     */
    public function testEncryptsWithRandomNonceAndContext(): void
    {
        $cipher = new TokenCipher(str_repeat('ab', 32));
        $first = $cipher->encrypt('fake-token', 'connection:1:user:2:access');
        self::assertNotSame($first, $cipher->encrypt('fake-token', 'connection:1:user:2:access'));
        self::assertStringNotContainsString('fake-token', $first);
        self::assertSame('fake-token', $cipher->decrypt($first, 'connection:1:user:2:access'));
    }

    /**
     * Rejects ciphertext copied to another user or token field.
     *
     * @return void
     */
    public function testRejectsAnotherRecordContext(): void
    {
        $cipher = new TokenCipher(str_repeat('ab', 32));
        $encrypted = $cipher->encrypt('fake-token', 'user:1:access');
        $this->expectException(AuthenticationException::class);
        $cipher->decrypt($encrypted, 'user:2:access');
    }

    /**
     * Rejects tampering and incorrect decryption keys.
     *
     * @return void
     */
    public function testRejectsTamperingAndWrongKeys(): void
    {
        $cipher = new TokenCipher(str_repeat('ab', 32));
        $encrypted = $cipher->encrypt('fake-token', 'user:1:refresh');
        foreach ([substr($encrypted, 0, -1), 'short'] as $invalid) {
            try {
                $cipher->decrypt($invalid, 'user:1:refresh');
                self::fail('Tampered token was accepted.');
            } catch (AuthenticationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(AuthenticationException::class);
        (new TokenCipher(str_repeat('cd', 32)))->decrypt($encrypted, 'user:1:refresh');
    }

    /**
     * Rejects missing or malformed operator-managed encryption keys.
     *
     * @return void
     */
    public function testRejectsInvalidKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TokenCipher('not-a-key');
    }
}
