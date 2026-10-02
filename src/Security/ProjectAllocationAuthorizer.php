<?php

declare(strict_types=1);

namespace Ordinal\Security;

use Ordinal\Model\AllocationCaller;
use Ordinal\Repository\TokenRepository;
use Ordinal\Repository\ProjectRepository;
use Ordinal\Service\AllocationException;
use SensitiveParameter;

/** Verifies project-scoped tokens locally; never contacts a repository provider. */
final class ProjectAllocationAuthorizer extends AllocationAuthorizer
{
    /** Tracks only identity proven by a matching secret. */
    private ?AllocationCaller $verifiedCaller = null;

    /**
     * Uses project state and hash-only token persistence.
     *
     * @param ProjectRepository $projects
     * @param TokenRepository $tokens
     */
    public function __construct(
        /** Reads archive state for independent projects. */
        private readonly ProjectRepository    $projects,
        /** Reads and validates project tokens. */
        private readonly TokenRepository $tokens,
    ) {}

    /**
     * Returns only the token identity verified for the current request.
     *
     * @return ?AllocationCaller
     */
    public function getVerifiedCaller(): ?AllocationCaller { return $this->verifiedCaller; }

    /**
     * Requires a current, non-revoked token assigned to this active project.
     *
     * @param int $projectId
     * @param ?string $bearerToken
     * @return AllocationCaller
     */
    public function authorizeAllocation(int $projectId, #[SensitiveParameter] ?string $bearerToken): AllocationCaller
    {
        $this->verifiedCaller = null;
        $project = $this->projects->findProject($projectId);
        if ($project === null || $project['archived_at'] !== null) { throw new AllocationException(AllocationException::PROJECT_NOT_FOUND); }
        $parsed = ProjectToken::parseToken($bearerToken);
        $token = $this->tokens->findToken($parsed['id']);
        $hash = hash('sha256', $parsed['secret']);
        if ($token === null || !hash_equals($token['secret_hash'], $hash)) { throw new AuthenticationException('A valid project token is required.'); }
        if ((int) $token['project_id'] !== $projectId) { throw new AllocationException(AllocationException::ACCESS_DENIED); }
        $this->verifiedCaller = new AllocationCaller(automationTokenId: $parsed['id'], automationSecretHash: $hash);
        $this->tokens->requireActiveToken($token, $projectId, $hash);
        return $this->verifiedCaller;
    }
}
