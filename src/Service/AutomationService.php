<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Closure;
use Ordinal\Database\Transaction;
use Ordinal\Model\BrowserSession;
use Ordinal\Repository\AdministrationRepository;
use Ordinal\Repository\AutomationRepository;
use Ordinal\Repository\ProjectRepository;
use Ordinal\Security\AutomationToken;
use PDO;

/** Protects project-scoped automation issuance, naming, expiry policy, rotation, and revocation. */
final readonly class AutomationService
{
    /** Keeps positive intervals inside PostgreSQL's timestamp range. */
    private const int MAX_LIFETIME_DAYS = 100_000_000;
    /** Bounds readable token names without silently truncating them. */
    private const int MAX_NAME_BYTES = 200;

    /**
     * Composes current-role checks and atomic token management.
     *
     * @param PDO $connection
     * @param AutomationRepository $repository
     * @param ProjectRepository $projectRepository
     * @param ProjectService $projects
     * @param BrowserSessionService $sessions
     * @param AdministrationService $administration
     * @param AdministrationRepository $audit
     */
    public function __construct(
        /** Owns atomic token mutations. */
        private PDO                      $connection,
        /** Persists stable tokens and assigned expiry. */
        private AutomationRepository     $repository,
        /** Rechecks active linked projects during mutations. */
        private ProjectRepository        $projectRepository,
        /** Computes current repository administrator permission. */
        private ProjectService           $projects,
        /** Rechecks browser identity and active form sessions. */
        private BrowserSessionService    $sessions,
        /** Protects instance policy and serializes grant changes. */
        private AdministrationService    $administration,
        /** Records verified actors and fixed token target IDs. */
        private AdministrationRepository $audit,
    ) {}

    /**
     * Issues the original secret once after current project/instance administration checks.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @param string $name
     * @param bool $hasNoExpiration
     * @return array
     */
    public function createToken(BrowserSession $session, int $projectId, string $name, bool $hasNoExpiration = false): array
    {
        $this->requireName($name);
        $result = [];
        $this->executeMutation($session, $projectId, null,
            /**
             * Saves only a hash and commits its target audit atomically.
             *
             * @param BrowserSession $current
             * @param ?array $token
             * @return void
             */
            function (BrowserSession $current, ?array $token) use ($projectId, $name, $hasNoExpiration, &$result): void {
                $secret = AutomationToken::createSecret();
                $token = $this->repository->createToken($projectId, $current->userId, trim($name), hash('sha256', $secret), $this->getAssignedLifetime($hasNoExpiration));
                $this->audit->recordEvent($current->userId, 'automation_token_created', $projectId, automationTokenId: (int) $token['id']);
                $result = $this->createReceipt($token, $secret);
            },
        );
        return $result;
    }

    /**
     * Invalidates the old secret and assigns current policy without changing stable identity.
     *
     * @param BrowserSession $session
     * @param int $id
     * @param bool $hasNoExpiration
     * @return array
     */
    public function rotateToken(BrowserSession $session, int $id, bool $hasNoExpiration = false): array
    {
        $token = $this->repository->findToken($id) ?? throw new AccountException('Automation token not found.', 404);
        $result = [];
        $this->executeMutation($session, (int) $token['project_id'], $id,
            /**
             * Rotates a locked active token and its audit within one transaction.
             *
             * @param BrowserSession $current
             * @param ?array $token
             * @return void
             */
            function (BrowserSession $current, ?array $token) use ($id, $hasNoExpiration, &$result): void {
                if ($token['revoked_at'] !== null) {
                    throw new AccountException('A revoked token cannot be rotated. Create a new token.');
                }
                $secret = AutomationToken::createSecret();
                $replacement = $this->repository->rotateToken($id, hash('sha256', $secret), $this->getAssignedLifetime($hasNoExpiration));
                $this->audit->recordEvent($current->userId, 'automation_token_rotated', (int) $token['project_id'], automationTokenId: $id);
                $result = $this->createReceipt($replacement, $secret);
            },
        );
        return $result;
    }

    /**
     * Changes only the readable name after current project administration checks.
     *
     * @param BrowserSession $session
     * @param int $id
     * @param string $name
     * @return void
     */
    public function renameToken(BrowserSession $session, int $id, string $name): void
    {
        $this->requireName($name);
        $token = $this->repository->findToken($id) ?? throw new AccountException('Automation token not found.', 404);
        $this->executeMutation($session, (int) $token['project_id'], $id,
            /**
             * Preserves identity and expiration while saving the new name and audit.
             *
             * @param BrowserSession $current
             * @param ?array $token
             * @return void
             */
            function (BrowserSession $current, ?array $token) use ($id, $name): void {
                $this->repository->renameToken($id, trim($name));
                $this->audit->recordEvent($current->userId, 'automation_token_renamed', (int) $token['project_id'], automationTokenId: $id);
            },
        );
    }

    /**
     * Revokes the stable token independently from device authentication.
     *
     * @param BrowserSession $session
     * @param int $id
     * @return void
     */
    public function revokeToken(BrowserSession $session, int $id): void
    {
        $token = $this->repository->findToken($id) ?? throw new AccountException('Automation token not found.', 404);
        $this->executeMutation($session, (int) $token['project_id'], $id,
            /**
             * Locks the token before revocation so waiting allocation callers recheck it.
             *
             * @param BrowserSession $current
             * @param ?array $token
             * @return void
             */
            function (BrowserSession $current, ?array $token) use ($id): void {
                $this->repository->revokeToken($id);
                $this->audit->recordEvent($current->userId, 'automation_token_revoked', (int) $token['project_id'], automationTokenId: $id);
            },
        );
    }

    /**
     * Shows metadata only to current project or instance administrators.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @return array
     */
    public function getProjectTokens(BrowserSession $session, int $projectId): array
    {
        if (!$this->projects->checkPermissions($session, $projectId)->canAdministerProject) {
            throw new AccountException('Project administration is required.');
        }
        return ['tokens' => $this->repository->findProjectTokens($projectId), 'policy' => $this->repository->getPolicy()];
    }

    /**
     * Applies positive-day/no-expiration settings only to future issuance and rotation.
     *
     * @param BrowserSession $session
     * @param int $lifetimeDays
     * @param bool $isWithoutExpirationAllowed
     * @return void
     */
    public function savePolicy(BrowserSession $session, int $lifetimeDays, bool $isWithoutExpirationAllowed): void
    {
        if ($lifetimeDays < 1 || $lifetimeDays > self::MAX_LIFETIME_DAYS) {
            throw new AccountException('Automation lifetime must be positive days within the database timestamp range.', 400);
        }
        $this->administration->executeMutation($session,
            /**
             * Persists instance policy and its verified actor audit.
             *
             * @param BrowserSession $current
             * @return void
             */
            function (BrowserSession $current) use ($lifetimeDays, $isWithoutExpirationAllowed): void {
                $this->repository->savePolicy($lifetimeDays, $isWithoutExpirationAllowed);
                $this->audit->recordEvent($current->userId, 'automation_policy_changed');
            },
        );
    }

    /**
     * Verifies provider administration before transaction locks and rechecks local grants, browser, token, and project inside them.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @param ?int $tokenId
     * @param Closure $operation
     * @return void
     */
    private function executeMutation(BrowserSession $session, int $projectId, ?int $tokenId, Closure $operation): void
    {
        $session = $this->sessions->requireActiveSession($session);
        $hasProviderAdministration = false;
        if (!$this->administration->isInstanceAdministrator($session->userId)) {
            $hasProviderAdministration = $this->projects->checkPermissions($session, $projectId)->canAdministerProject;
            if (!$hasProviderAdministration) {
                throw new AccountException('Project administration is required.');
            }
        }
        (new Transaction($this->connection))->execute(
            /**
             * Uses settings/session/token/project lock order consistently with allocation and role revocation.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($session, $projectId, $tokenId, $operation, $hasProviderAdministration): void {
                $this->administration->lockSettings();
                $current = $this->sessions->requireActiveSession($session, true);
                if (!$hasProviderAdministration && !$this->administration->isInstanceAdministrator($current->userId)) {
                    throw new AccountException('Project administration is required.');
                }
                $token = $tokenId === null ? null : $this->repository->findToken($tokenId, true);
                if ($tokenId !== null && ($token === null || (int) $token['project_id'] !== $projectId)) {
                    throw new AccountException('Automation token not found.', 404);
                }
                $project = $this->projectRepository->findProject($projectId, true);
                if ($project === null || $project['archived_at'] !== null || $project['connection_disabled_at'] !== null) {
                    throw new AccountException('Project not found.', 404);
                }
                $operation($current, $token);
            },
        );
    }

    /**
     * Resolves a separately authorized no-expiration request or the current positive-day default.
     *
     * @param bool $hasNoExpiration
     * @return ?int
     */
    private function getAssignedLifetime(bool $hasNoExpiration): ?int
    {
        $policy = $this->repository->getPolicy();
        if ($hasNoExpiration && !$policy['is_automation_without_expiration_allowed']) {
            throw new AccountException('Tokens without expiration are disabled by instance policy.');
        }
        return $hasNoExpiration ? null : (int) $policy['automation_lifetime_days'];
    }

    /**
     * Requires a readable bounded token name.
     *
     * @param string $name
     * @return void
     */
    private function requireName(string $name): void
    {
        if (trim($name) === '' || strlen($name) > self::MAX_NAME_BYTES) {
            throw new AccountException('Choose a readable automation token name up to 200 bytes.', 400);
        }
    }

    /**
     * Returns issuance metadata and the original secret without a stored hash.
     *
     * @param array $token
     * @param string $secret
     * @return array
     */
    private function createReceipt(array $token, #[\SensitiveParameter] string $secret): array
    {
        return ['id' => (int) $token['id'], 'projectId' => (int) $token['project_id'], 'name' => $token['name'], 'expiresAt' => $token['expires_at'],
            'token' => AutomationToken::createToken((int) $token['id'], $secret)];
    }
}
