<?php

declare(strict_types=1);

namespace Ordinal\Security;

use Ordinal\Model\AllocationCaller;
use Ordinal\Provider\ProviderAuthenticationException;
use Ordinal\Provider\ProviderUnavailableException;
use Ordinal\Repository\DeviceRepository;
use Ordinal\Service\AllocationException;
use Ordinal\Service\RepositoryAccessService;
use SensitiveParameter;

/** Validates local device credentials before fresh provider authorization on every request. */
final class DeviceAllocationAuthorizer extends AllocationAuthorizer
{
    /**
     * Uses local validity and current provider write permission.
     *
     * @param DeviceRepository $repository
     * @param RepositoryAccessService $access
     */
    public function __construct(
        /** Reads project policy and hashed credential records. */
        private readonly DeviceRepository        $repository,
        /** Verifies repository write permission without administrator shortcuts. */
        private readonly RepositoryAccessService $access,
    ) {}

    /**
     * Rejects local invalidity before provider checks and permits explicit anonymous policy.
     *
     * @param int $projectId
     * @param ?string $bearerToken
     * @return AllocationCaller
     */
    public function authorizeAllocation(int $projectId, #[SensitiveParameter] ?string $bearerToken): AllocationCaller
    {
        $project = $this->repository->findProjectPolicy($projectId);
        if ($project === null || $project['archived_at'] !== null || $project['connection_disabled_at'] !== null) {
            throw new AllocationException(AllocationException::PROJECT_NOT_FOUND);
        }
        if (!$project['is_authentication_required']) {
            return new AllocationCaller();
        }
        $token = DeviceToken::parseToken($bearerToken);
        $credential = $this->repository->findCredential($token['id']);
        if ($credential === null || !hash_equals($credential['secret_hash'], hash('sha256', $token['secret']))) {
            throw new AuthenticationException('Device authentication is required or invalid.');
        }
        if ((int) $credential['project_id'] !== $projectId) {
            throw new AllocationException(AllocationException::ACCESS_DENIED);
        }
        $userId = (int) $credential['user_id'];
        $this->repository->requireActiveCredential($credential, $projectId, $userId);
        try {
            if (!$this->access->canAllocate($userId, $project)) {
                throw new AllocationException(AllocationException::ACCESS_DENIED);
            }
        } catch (ProviderAuthenticationException) {
            throw new AuthenticationException('Provider sign-in is required.');
        } catch (ProviderUnavailableException) {
            throw new AllocationException(AllocationException::PROVIDER_UNAVAILABLE);
        }
        return new AllocationCaller($userId, deviceCredentialId: $token['id']);
    }
}
