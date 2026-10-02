<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Ordinal\Model\BrowserSession;
use Ordinal\Model\ProviderIdentity;
use Ordinal\Model\ProviderRepository;
use Ordinal\Model\RepositoryPermissions;
use Ordinal\Repository\AdministrationRepository;
use Ordinal\Repository\ProjectRepository;

/** Creates protected repository links and derives project access from current provider permissions. */
final readonly class ProjectService
{
    /**
     * Composes current permission checks and atomic project creation.
     *
     * @param ProjectRepository $repository
     * @param ProviderRegistry $providers
     * @param AuthorizationService $authorizations
     * @param BrowserSessionService $sessions
     * @param AdministrationService $administration
     * @param AdministrationRepository $audit
     */
    public function __construct(
        /** Persists linked project identities and counters. */
        private ProjectRepository        $repository,
        /** Resolves active configured provider connections. */
        private ProviderRegistry         $providers,
        /** Obtains the current encrypted/rotated user authorization. */
        private AuthorizationService     $authorizations,
        /** Rejects stale browser sessions. */
        private BrowserSessionService    $sessions,
        /** Restricts project creation to instance administrators. */
        private AdministrationService    $administration,
        /** Records project creation in the same transaction. */
        private AdministrationRepository $audit,
    ) {}

    /**
     * Creates a project for a repository verified through the selected provider identity.
     *
     * @param BrowserSession $session
     * @param int $connectionId
     * @param string $repositoryId
     * @param string $name
     * @return int
     */
    public function createProject(BrowserSession $session, int $connectionId, string $repositoryId, string $name): int
    {
        $this->administration->requireInstanceAdministrator($session);
        if (trim($name) === '' || preg_match('/^[1-9][0-9]*$/D', $repositoryId) !== 1) {
            throw new AccountException('Choose an immutable repository ID and a project name.', 400);
        }
        if ($session->providerConnectionId !== $connectionId) {
            throw new AccountException('Sign in through the selected repository provider before linking it.');
        }
        $provider = $this->providers->createProvider($connectionId);
        $authorization = $this->authorizations->getAuthorization($session->userId);
        $repository = $provider->findRepository($authorization, $repositoryId);
        $id = 0;
        $this->administration->executeMutation($session,
            /**
             * Rechecks current instance rights and commits the immutable link with its audit event.
             *
             * @param BrowserSession $current
             * @return void
             */
            function (BrowserSession $current) use ($repository, $name, &$id): void {
                $this->providers->createProvider($repository->providerConnectionId);
                $id = $this->repository->createProject($repository, trim($name)) ?? throw new AccountException('This repository already has a project.', 409);
                $this->audit->recordEvent($current->userId, 'project_created', $id);
            },
        );
        return $id;
    }

    /**
     * Computes project rights on each request without granting cross-provider identity access.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @param bool $canReadInactive
     * @return RepositoryPermissions
     */
    public function checkPermissions(BrowserSession $session, int $projectId, bool $canReadInactive = false): RepositoryPermissions
    {
        $session = $this->sessions->requireActiveSession($session);
        $project = $this->repository->findProject($projectId);
        if ($project === null) {
            throw new AccountException('Project not found.', 404);
        }
        if ($canReadInactive && $this->administration->isInstanceAdministrator($session->userId)) {
            return new RepositoryPermissions(true, true);
        }
        if ((!$canReadInactive && $project['archived_at'] !== null) || $project['connection_disabled_at'] !== null) {
            return new RepositoryPermissions();
        }
        if ($this->administration->isInstanceAdministrator($session->userId)) {
            return new RepositoryPermissions(true, true);
        }
        if ($session->providerConnectionId !== (int) $project['provider_connection_id']) {
            return new RepositoryPermissions();
        }
        $provider = $this->providers->createProvider($session->providerConnectionId);
        $authorization = $this->authorizations->getAuthorization($session->userId);
        return $provider->checkRepositoryPermissions($authorization, new ProviderIdentity($session->providerConnectionId, $session->providerUserId, '', $session->displayName), new ProviderRepository($session->providerConnectionId, $project['provider_repository_id'], $project['name']));
    }

    /**
     * Lists only projects accessible to the current provider-qualified browser identity.
     *
     * @param BrowserSession $session
     * @return array
     */
    public function findVisibleProjects(BrowserSession $session): array
    {
        $this->sessions->requireActiveSession($session);
        $visible = [];
        foreach ($this->repository->findProjects() as $project) {
            if ($this->administration->isInstanceAdministrator($session->userId)) {
                $visible[] = $project;
                continue;
            }
            if ((int) $project['provider_connection_id'] === $session->providerConnectionId && $this->checkPermissions($session, (int) $project['id'])->canAllocateBuildNumber) {
                $visible[] = $project;
            }
        }
        return $visible;
    }

    /**
     * Returns project data only after current contributor/administrator access is established.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @return array
     */
    public function getProject(BrowserSession $session, int $projectId): array
    {
        $permissions = $this->checkPermissions($session, $projectId, true);
        if (!$permissions->canAllocateBuildNumber) {
            throw new AccountException('Repository access is required.');
        }
        return ['project' => $this->repository->findProject($projectId), 'permissions' => $permissions];
    }
}
