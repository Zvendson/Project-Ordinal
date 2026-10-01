<?php

declare(strict_types=1);

namespace Ordinal\Provider;

use InvalidArgumentException;
use Ordinal\Model\ProviderAuthorization;
use Ordinal\Model\ProviderIdentity;
use Ordinal\Model\ProviderRepository;
use Ordinal\Model\RepositoryPermissions;
use SensitiveParameter;

/**
 * Defines shared provider operations and verifies connection and caller identity boundaries.
 */
abstract class Provider
{
    /**
     * Binds the provider to a positive configured connection ID.
     *
     * @param int $providerConnectionId
     * @throws \InvalidArgumentException
     */
    public function __construct(
        /** Identifies the configured provider connection. */
        private readonly int $providerConnectionId,
    ) {
        if ($providerConnectionId <= 0) {
            throw new InvalidArgumentException('Provider connection ID must be positive.');
        }
    }

    /**
     * Returns the configured connection identifier.
     *
     * @return int
     */
    final public function getProviderConnectionId(): int
    {
        return $this->providerConnectionId;
    }

    /**
     * Builds the provider login URL using caller-supplied state and PKCE challenge.
     *
     * @param string $state
     * @param string $codeChallenge
     * @return string
     */
    abstract public function createAuthorizationUrl(string $state, string $codeChallenge): string;

    /**
     * Exchanges a login code and its original PKCE verifier for provider credentials.
     *
     * @param string $authorizationCode
     * @param string $codeVerifier
     * @return ProviderAuthorization
     */
    abstract public function exchangeAuthorizationCode(
        #[SensitiveParameter]
        string $authorizationCode,
        #[SensitiveParameter]
        string $codeVerifier,
    ): ProviderAuthorization;

    /**
     * Obtains replacement provider credentials using the existing authorization.
     *
     * @param ProviderAuthorization $authorization
     * @return ProviderAuthorization
     */
    abstract public function refreshAuthorization(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
    ): ProviderAuthorization;

    /**
     * Looks up the immutable identity belonging to the provider authorization.
     *
     * @param ProviderAuthorization $authorization
     * @return ProviderIdentity
     */
    abstract public function findIdentity(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
    ): ProviderIdentity;

    /**
     * Looks up repository metadata using its immutable provider repository ID.
     *
     * @param ProviderAuthorization $authorization
     * @param string $providerRepositoryId
     * @return ProviderRepository
     */
    abstract public function findRepository(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        string                $providerRepositoryId,
    ): ProviderRepository;

    /**
     * Verifies connection and token-holder identity before checking repository access.
     *
     * @param ProviderAuthorization $authorization
     * @param ProviderIdentity $identity
     * @param ProviderRepository $repository
     * @return RepositoryPermissions
     * @throws \InvalidArgumentException
     */
    final public function checkRepositoryPermissions(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        ProviderIdentity      $identity,
        ProviderRepository    $repository,
    ): RepositoryPermissions {
        $recordConnectionIds = [
            $authorization->providerConnectionId,
            $identity->providerConnectionId,
            $repository->providerConnectionId,
        ];

        foreach ($recordConnectionIds as $recordConnectionId) {
            if ($recordConnectionId !== $this->providerConnectionId) {
                throw new InvalidArgumentException('Provider records must belong to the same connection.');
            }
        }

        $verifiedIdentity = $this->findIdentity($authorization);

        if ($verifiedIdentity->providerConnectionId !== $this->providerConnectionId
            || $verifiedIdentity->providerUserId !== $identity->providerUserId) {
            throw new InvalidArgumentException('Provider authorization must belong to the requested identity.');
        }

        return $this->fetchRepositoryPermissions($authorization, $verifiedIdentity, $repository);
    }

    /**
     * Obtains current effective repository permissions from the concrete provider.
     *
     * @param ProviderAuthorization $authorization
     * @param ProviderIdentity $identity
     * @param ProviderRepository $repository
     * @return RepositoryPermissions
     */
    abstract protected function fetchRepositoryPermissions(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        ProviderIdentity      $identity,
        ProviderRepository    $repository,
    ): RepositoryPermissions;
}
