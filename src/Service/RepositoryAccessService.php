<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Ordinal\Model\ProviderIdentity;
use Ordinal\Model\ProviderRepository;
use Ordinal\Repository\IdentityRepository;

/** Verifies current repository write permission without an instance-administrator bypass. */
final readonly class RepositoryAccessService
{
    /**
     * Composes fresh provider checks for enrollment, allocation, and replay.
     *
     * @param IdentityRepository $identities
     * @param ProviderRegistry $providers
     * @param AuthorizationService $authorizations
     */
    public function __construct(
        /** Reads immutable provider-qualified user identities. */
        private IdentityRepository   $identities,
        /** Resolves active configured providers. */
        private ProviderRegistry     $providers,
        /** Obtains reusable encrypted provider authorization. */
        private AuthorizationService $authorizations,
    ) {}

    /**
     * Checks current write permission; upstream failures propagate.
     *
     * @param int $userId
     * @param array $project
     * @return bool
     */
    public function canAllocate(int $userId, array $project): bool
    {
        $user = $this->identities->findUser($userId);
        if ($user === null || (int) $user['provider_connection_id'] !== (int) $project['provider_connection_id']) {
            return false;
        }
        $provider = $this->providers->createProvider((int) $user['provider_connection_id']);
        $authorization = $this->authorizations->getAuthorization($userId);
        $permissions = $provider->checkRepositoryPermissions($authorization,
            new ProviderIdentity((int) $user['provider_connection_id'], $user['provider_user_id'], '', $user['display_name']),
            new ProviderRepository((int) $project['provider_connection_id'], $project['provider_repository_id'], $project['name']));
        return $permissions->canAllocateBuildNumber;
    }
}
