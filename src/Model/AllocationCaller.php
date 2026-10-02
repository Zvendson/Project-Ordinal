<?php

declare(strict_types=1);

namespace Ordinal\Model;

use InvalidArgumentException;
use SensitiveParameter;

/** Carries stable caller IDs supplied by trusted authorization, never raw request fields. */
final readonly class AllocationCaller
{
    /** Names the verified user retry namespace. */
    public const string USER = 'user';
    /** Names the stable automation-token retry namespace. */
    public const string AUTOMATION = 'automation';
    /** Names the anonymous retry namespace. */
    public const string ANONYMOUS = 'anonymous';

    /**
     * Stores one caller category and an optional user-owned device credential.
     *
     * @param ?int $userId
     * @param ?int $automationTokenId
     * @param ?int $deviceCredentialId
     * @param ?string $automationSecretHash
     * @throws InvalidArgumentException
     */
    public function __construct(
        /** Identifies the verified provider user. */
        public ?int    $userId               = null,
        /** Identifies the verified stable automation token. */
        public ?int    $automationTokenId    = null,
        /** Identifies the verified originating device credential. */
        public ?int    $deviceCredentialId   = null,
        /** Carries the verified hash for transaction-bound rotation checks; never the Bearer secret. */
        #[SensitiveParameter]
        public ?string $automationSecretHash = null,
    ) {
        if (($userId !== null && $automationTokenId !== null)
            || ($deviceCredentialId !== null && $userId === null)
            || ($automationSecretHash !== null && ($automationTokenId === null || preg_match('/^[a-f0-9]{64}$/D', $automationSecretHash) !== 1))) {
            throw new InvalidArgumentException('Caller relationships are inconsistent.');
        }
        foreach ([$userId, $automationTokenId, $deviceCredentialId] as $id) {
            if ($id !== null && $id <= 0) {
                throw new InvalidArgumentException('Caller IDs must be positive.');
            }
        }
    }

    /**
     * Identifies the permanent retry namespace for this caller.
     *
     * @return string
     */
    public function getKind(): string
    {
        if ($this->userId !== null) {
            return self::USER;
        }
        return $this->automationTokenId !== null ? self::AUTOMATION : self::ANONYMOUS;
    }
}
