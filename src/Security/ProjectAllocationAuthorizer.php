<?php

declare(strict_types=1);

namespace Ordinal\Security;

use Ordinal\Model\AllocationCaller;
use Ordinal\Provider\ProviderAuthenticationException;
use Ordinal\Provider\ProviderUnavailableException;
use Ordinal\Repository\DeviceRepository;
use Ordinal\Repository\AutomationRepository;
use Ordinal\Service\AllocationException;
use Ordinal\Service\RepositoryAccessService;
use SensitiveParameter;

/** Authorizes anonymous policy, local CI credentials, or devices with fresh provider verification. */
final class ProjectAllocationAuthorizer extends AllocationAuthorizer
{
    /** Holds only identity proven by this request's credential secret, not an authorization grant. */
    private ?AllocationCaller $verifiedCaller = null;

    /**
     * Returns verified identity without granting allocation permission.
     *
     * @return ?AllocationCaller
     */
    public function getVerifiedCaller(): ?AllocationCaller { return $this->verifiedCaller; }
    /**
     * Checks CI tokens locally and verifies current provider write permission for devices.
     *
     * @param DeviceRepository $repository
     * @param RepositoryAccessService $access
     * @param AutomationRepository $automation
     */
    public function __construct(
        /** Reads project policy and hashed credential records. */
        private readonly DeviceRepository        $repository,
        /** Verifies repository write permission without administrator shortcuts. */
        private readonly RepositoryAccessService $access,
        /** Validates project-scoped automation locally without contacting providers. */
        private readonly AutomationRepository    $automation,
    ) {}

    /**
     * Rejects local invalidity before provider checks and permits explicit anonymous policy.
     *
     * @param int $projectId
     * @param ?string $bearerToken
     * @return AllocationCaller
     */
    public function authorizeAllocation(
        int     $projectId,
        #[SensitiveParameter]
        ?string $bearerToken,
    ): AllocationCaller
    {
        $this->verifiedCaller = null;
        $project = $this->repository->findProjectPolicy($projectId);
        if ($project === null || $project['archived_at'] !== null || $project['connection_disabled_at'] !== null) {
            throw new AllocationException(AllocationException::PROJECT_NOT_FOUND);
        }
        if (!$project['is_authentication_required']) {
            return $this->verifiedCaller = new AllocationCaller();
        }
        $isAutomation = str_starts_with($bearerToken ?? '', 'automation.');
        $token = $isAutomation ? AutomationToken::parseToken($bearerToken) : DeviceToken::parseToken($bearerToken);
        $credential = $isAutomation ? $this->automation->findToken($token['id']) : $this->repository->findCredential($token['id']);
        if ($credential === null || !hash_equals($credential['secret_hash'], hash('sha256', $token['secret']))) {
            throw new AuthenticationException('Project authentication is required or invalid.');
        }
        if ((int) $credential['project_id'] !== $projectId) {
            throw new AllocationException(AllocationException::ACCESS_DENIED);
        }
        if ($isAutomation) {
            $hash = hash('sha256', $token['secret']);
            $this->verifiedCaller = new AllocationCaller(automationTokenId: $token['id'], automationSecretHash: $hash);
            $this->automation->requireActiveToken($credential, $projectId, $hash);
            return $this->verifiedCaller;
        }
        $userId = (int) $credential['user_id'];
        $this->verifiedCaller = new AllocationCaller($userId, deviceCredentialId: $token['id']);
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
        return $this->verifiedCaller;
    }
}
