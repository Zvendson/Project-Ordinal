<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Ordinal\Database\Transaction;
use Ordinal\Model\BrowserSession;
use Ordinal\Repository\AdministrationRepository;
use Ordinal\Repository\DeviceRepository;
use Ordinal\Security\DeviceToken;
use PDO;

/** Enrolls named devices through provider-authenticated sessions and protects policy and revocation. */
final readonly class DeviceService
{
    /** Requires a recent actual provider sign-in for each single-allocation credential. */
    private const int FRESH_SIGN_IN_SECONDS = 300;
    /** Keeps positive-day intervals inside PostgreSQL's timestamp range. */
    private const int MAX_LIFETIME_DAYS = 100_000_000;
    /** Bounds readable device names without silently truncating them. */
    private const int MAX_NAME_BYTES = 200;

    /**
     * Composes protected enrollment and transaction-bound revocation.
     *
     * @param PDO $connection
     * @param DeviceRepository $repository
     * @param RepositoryAccessService $access
     * @param BrowserSessionService $sessions
     * @param AdministrationService $administration
     * @param AdministrationRepository $audit
     * @param ProjectService $projects
     */
    public function __construct(
        /** Owns enrollment and owner-revocation transactions. */
        private PDO                      $connection,
        /** Persists device identity, hashes, and assigned policy. */
        private DeviceRepository         $repository,
        /** Requires current write permission for enrollment, including instance administrators. */
        private RepositoryAccessService  $access,
        /** Rechecks current browser identity and sign-in time. */
        private BrowserSessionService    $sessions,
        /** Protects instance defaults and project overrides. */
        private AdministrationService    $administration,
        /** Records non-secret administrative events. */
        private AdministrationRepository $audit,
        /** Checks project administrator permission for individual credential revocation. */
        private ProjectService           $projects,
    ) {}

    /**
     * Approves one project credential and returns its original secret only to this response.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @param string $name
     * @param ?int $deviceId
     * @return array
     */
    public function enrollDevice(BrowserSession $session, int $projectId, string $name, ?int $deviceId = null): array
    {
        $session = $this->sessions->requireActiveSession($session);
        if (($deviceId !== null && $deviceId < 1) || ($deviceId === null && (trim($name) === '' || strlen($name) > self::MAX_NAME_BYTES))) {
            throw new AccountException('Choose a readable device name or an existing device ID.', 400);
        }
        $project = $this->repository->findProjectPolicy($projectId);
        $this->requireActiveProject($project);
        if (!$this->access->canAllocate($session->userId, $project)) {
            throw new AccountException('Current repository write permission is required.');
        }
        $result = [];
        (new Transaction($this->connection))->execute(
            /**
             * Rechecks the browser, device, and current assigned policy before saving the hash and audit.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($session, $projectId, $name, $deviceId, &$result): void {
                $current = $this->sessions->requireActiveSession($session, true);
                $id = $deviceId;
                if ($id !== null) {
                    $device = $this->repository->lockDevice($id);
                    if ($device === null || (int) $device['user_id'] !== $current->userId || $device['revoked_at'] !== null) {
                        throw new AccountException('Choose an active device belonging to this account.');
                    }
                } else {
                    $id = $this->repository->createDevice($current->userId, trim($name));
                }
                $project = $this->repository->findProjectPolicy($projectId, true);
                $this->requireActiveProject($project);
                $authenticatedAt = $current->providerReauthenticatedAt;
                if ($authenticatedAt === null || ($project['device_lifetime_days'] === 0 && $authenticatedAt->getTimestamp() < time() - self::FRESH_SIGN_IN_SECONDS)) {
                    throw new AccountException('Sign in through your provider again before approving this credential.', 401);
                }
                $secret = DeviceToken::createSecret();
                $credential = $this->repository->createCredential($id, $current->userId, $projectId, hash('sha256', $secret), (int) $project['device_lifetime_days'], $authenticatedAt->format('Y-m-d H:i:s.uP'));
                if ($credential === null) {
                    throw new AccountException('This sign-in already approved a single-allocation credential. Sign in again.', 401);
                }
                $this->audit->recordEvent($current->userId, 'device_credential_created', $projectId, $id, (int) $credential['id']);
                $result = ['deviceId' => $id, 'credentialId' => (int) $credential['id'], 'projectId' => $projectId,
                    'token' => DeviceToken::createToken((int) $credential['id'], $secret), 'lifetimeDays' => (int) $credential['lifetime_days'],
                    'authenticatedAt' => $credential['authenticated_at'], 'expiresAt' => $credential['expires_at']];
            },
        );
        return $result;
    }

    /**
     * Returns owner metadata or all device metadata to current instance administrators.
     *
     * @param BrowserSession $session
     * @param ?int $projectId
     * @return array
     */
    public function getDeviceData(BrowserSession $session, ?int $projectId = null): array
    {
        $session = $this->sessions->requireActiveSession($session);
        $canReadProjectCredentials = false;
        if ($projectId !== null) {
            $project = $this->projects->getProject($session, $projectId);
            $canReadProjectCredentials = $project['permissions']->canAdministerProject;
        }
        return $this->repository->findDeviceData($session->userId, $this->administration->isInstanceAdministrator($session->userId), $projectId, $canReadProjectCredentials);
    }

    /**
     * Exposes effective authentication settings only after project access is checked.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @return array
     */
    public function getProjectPolicy(BrowserSession $session, int $projectId): array
    {
        $this->projects->getProject($session, $projectId);
        return $this->repository->findProjectPolicy($projectId) ?? throw new AccountException('Project not found.', 404);
    }

    /**
     * Changes defaults or nullable overrides for future authentications only.
     *
     * @param BrowserSession $session
     * @param ?int $projectId
     * @param ?bool $isRequired
     * @param ?int $lifetimeDays
     * @return void
     */
    public function saveAuthenticationPolicy(BrowserSession $session, ?int $projectId, ?bool $isRequired, ?int $lifetimeDays): void
    {
        if (($projectId === null && ($isRequired === null || $lifetimeDays === null)) || ($projectId !== null && $projectId < 1)
            || ($lifetimeDays !== null && ($lifetimeDays < -1 || $lifetimeDays > self::MAX_LIFETIME_DAYS))) {
            throw new AccountException('Choose authentication settings with a lifetime of -1, 0, or positive days within the database timestamp range.', 400);
        }
        $this->administration->executeMutation($session,
            /**
             * Serializes administrator rights, policy mutation, and its non-secret audit.
             *
             * @param BrowserSession $current
             * @return void
             */
            function (BrowserSession $current) use ($projectId, $isRequired, $lifetimeDays): void {
                if ($projectId !== null && $this->repository->findProjectPolicy($projectId) === null) {
                    throw new AccountException('Project not found.', 404);
                }
                $this->repository->saveAuthenticationPolicy($projectId, $isRequired, $lifetimeDays);
                $this->audit->recordEvent($current->userId, 'authentication_policy_changed', $projectId);
            },
        );
    }

    /**
     * Allows the owner, instance administrator, or current project administrator to revoke one credential.
     *
     * @param BrowserSession $session
     * @param int $credentialId
     * @return void
     */
    public function revokeCredential(BrowserSession $session, int $credentialId): void
    {
        $session = $this->sessions->requireActiveSession($session);
        $credential = $this->repository->findCredential($credentialId) ?? throw new AccountException('Credential not found.', 404);
        $isProjectAdministrator = false;
        if ((int) $credential['user_id'] !== $session->userId && !$this->administration->isInstanceAdministrator($session->userId)) {
            $isProjectAdministrator = $this->projects->checkPermissions($session, (int) $credential['project_id'])->canAdministerProject;
            if (!$isProjectAdministrator) {
                throw new AccountException('Device ownership or administration is required.');
            }
        }
        (new Transaction($this->connection))->execute(
            /**
             * Locks the session, device, and credential before preserving the revocation event.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($session, $credentialId, $isProjectAdministrator): void {
                $this->administration->lockSettings();
                $current = $this->sessions->requireActiveSession($session, true);
                $credential = $this->repository->findCredential($credentialId, true) ?? throw new AccountException('Credential not found.', 404);
                if ((int) $credential['user_id'] !== $current->userId && !$isProjectAdministrator && !$this->administration->isInstanceAdministrator($current->userId)) {
                    throw new AccountException('Device ownership or administration is required.');
                }
                $this->repository->revokeCredential($credentialId);
                $this->audit->recordEvent($current->userId, 'device_credential_revoked', (int) $credential['project_id'], (int) $credential['device_id'], $credentialId);
            },
        );
    }

    /**
     * Revokes every project credential of an owned device; instance administrators may revoke any device.
     *
     * @param BrowserSession $session
     * @param int $deviceId
     * @return void
     */
    public function revokeDevice(BrowserSession $session, int $deviceId): void
    {
        $this->sessions->requireActiveSession($session);
        (new Transaction($this->connection))->execute(
            /**
             * Serializes device-wide revocation against enrollment and allocation.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($session, $deviceId): void {
                $this->administration->lockSettings();
                $current = $this->sessions->requireActiveSession($session, true);
                $device = $this->repository->lockDevice($deviceId) ?? throw new AccountException('Device not found.', 404);
                if ((int) $device['user_id'] !== $current->userId && !$this->administration->isInstanceAdministrator($current->userId)) {
                    throw new AccountException('Device ownership or instance administration is required.');
                }
                $this->repository->revokeDevice($deviceId);
                $this->audit->recordEvent($current->userId, 'device_revoked', deviceId: $deviceId);
            },
        );
    }

    /**
     * Rejects archived projects and disabled connections before enrollment.
     *
     * @param ?array $project
     * @return void
     */
    private function requireActiveProject(?array $project): void
    {
        if ($project === null || $project['archived_at'] !== null || $project['connection_disabled_at'] !== null) {
            throw new AccountException('Project not found.', 404);
        }
    }
}
