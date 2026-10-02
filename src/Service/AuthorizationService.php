<?php

declare(strict_types=1);

namespace Ordinal\Service;

use DateTimeImmutable;
use Ordinal\Database\Transaction;
use Ordinal\Model\ProviderAuthorization;
use Ordinal\Provider\ProviderAuthenticationException;
use Ordinal\Repository\AuthorizationRepository;
use Ordinal\Security\AuthenticationException;
use PDO;

/** Coordinates encrypted provider credentials so concurrent refreshes cannot overwrite rotated tokens. */
final readonly class AuthorizationService
{
    /** Allows expiry-sensitive API calls to refresh slightly before access expiration. */
    private const int EXPIRY_MARGIN_SECONDS = 30;

    /**
     * Uses the same database/user lock as login authorization writes.
     *
     * @param PDO $connection
     * @param AuthorizationRepository $repository
     * @param ProviderRegistry $providers
     */
    public function __construct(
        /** Owns the authorization transaction. */
        private PDO                     $connection,
        /** Reads and writes authenticated ciphertext. */
        private AuthorizationRepository $repository,
        /** Resolves the allowed provider for each identity. */
        private ProviderRegistry        $providers,
    ) {}

    /**
     * Obtains current credentials, rotating once under the user-row mutex when necessary.
     *
     * @param int $userId
     * @return ProviderAuthorization
     * @throws AuthenticationException
     * @throws ProviderAuthenticationException
     * @throws \Ordinal\Provider\ProviderUnavailableException
     */
    public function getAuthorization(int $userId): ProviderAuthorization
    {
        $authorization = null;
        $version = -1;
        $isRefreshAttempted = false;
        try {
            (new Transaction($this->connection))->execute(
                /**
                 * Locks before reading the latest token pair, then commits any successful replacement.
                 *
                 * @param PDO $connection
                 * @return void
                 */
                function (PDO $connection) use ($userId, &$authorization, &$version, &$isRefreshAttempted): void {
                    $user = $this->repository->lockUser($userId);
                    $provider = $this->providers->createProvider((int) $user['provider_connection_id']);
                    $version = $this->repository->getVersion($userId);
                    $authorization = $this->repository->findAuthorization($userId, (int) $user['provider_connection_id']);
                    if ($authorization->expiresAt !== null && $authorization->expiresAt->getTimestamp() <= time() + self::EXPIRY_MARGIN_SECONDS) {
                        $isRefreshAttempted = true;
                        if ($authorization->refreshExpiresAt !== null && $authorization->refreshExpiresAt <= new DateTimeImmutable()) {
                            throw new ProviderAuthenticationException('Provider sign-in is required.');
                        }
                        $replacement = $provider->refreshAuthorization($authorization);
                        $identity = $provider->findIdentity($replacement);
                        if ($identity->providerConnectionId !== $authorization->providerConnectionId || $identity->providerUserId !== $user['provider_user_id']) {
                            throw new AuthenticationException('Refreshed authorization belongs to another identity.');
                        }
                        $this->repository->saveAuthorization($userId, $replacement);
                        $authorization = $replacement;
                    }
                },
            );
        } catch (ProviderAuthenticationException $exception) {
            if ($isRefreshAttempted && $version >= 0) {
                (new Transaction($this->connection))->execute(
                    /**
                     * Invalidates only the rejected version after rollback, preserving any newer concurrent login.
                     *
                     * @param PDO $connection
                     * @return void
                     */
                    function (PDO $connection) use ($userId, $version): void {
                        $this->repository->lockUser($userId);
                        $this->repository->revokeAuthorization($userId, $version);
                    },
                );
            }
            throw $exception;
        }
        return $authorization;
    }
}
