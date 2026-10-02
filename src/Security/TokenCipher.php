<?php

declare(strict_types=1);

namespace Ordinal\Security;

use InvalidArgumentException;
use SensitiveParameter;

/** Encrypts reusable credentials with authenticated record binding and random nonces. */
final readonly class TokenCipher
{
    /** Stores the decoded operator-managed 256-bit encryption key. */
    private string $key;
    /** Marks the current ciphertext format for future controlled migration. */
    private const string FORMAT = "\x01";

    /**
     * Requires a 32-byte hex-encoded key supplied outside the database.
     *
     * @param string $hexKey
     * @throws InvalidArgumentException
     */
    public function __construct(#[SensitiveParameter] string $hexKey)
    {
        if (preg_match('/^[a-fA-F0-9]{64}$/D', $hexKey) !== 1) {
            throw new InvalidArgumentException('Provider encryption key must contain 32 hex-encoded bytes.');
        }
        $this->key = hex2bin($hexKey);
    }

    /**
     * Encrypts a token using additional authenticated context for its record and field.
     *
     * @param string $plaintext
     * @param string $context
     * @return string
     */
    public function encrypt(#[SensitiveParameter] string $plaintext, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return self::FORMAT . $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $this->key);
    }

    /**
     * Rejects tampering, wrong keys, or copying ciphertext to another record.
     *
     * @param string $ciphertext
     * @param string $context
     * @return string
     * @throws AuthenticationException
     */
    public function decrypt(#[SensitiveParameter] string $ciphertext, string $context): string
    {
        $offset = strlen(self::FORMAT) + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($ciphertext) <= $offset + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES || !str_starts_with($ciphertext, self::FORMAT)) {
            throw new AuthenticationException('Stored provider authorization could not be verified.');
        }
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($ciphertext, $offset), $context, substr($ciphertext, strlen(self::FORMAT), SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES), $this->key);
        if ($plaintext === false) {
            throw new AuthenticationException('Stored provider authorization could not be verified.');
        }
        return $plaintext;
    }
}
