<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Closure;
use Ordinal\Database\Transaction;
use Ordinal\Model\BrowserSession;
use Ordinal\Model\SecurityConfiguration;
use Ordinal\Repository\AdministrationRepository;
use Ordinal\Repository\IdentityRepository;
use Ordinal\Repository\ProviderConnectionRepository;
use PDO;

/** Protects bootstrap/last administration and serializes instance policy mutations. */
final readonly class AdministrationService
{
    /** Limits sensitive administrator changes to a recent provider sign-in. */
    private const int REAUTHENTICATION_SECONDS = 300;
    /** Matches PostgreSQL's positive INTEGER range for session-minute settings. */
    private const int MAX_MINUTES = 2_147_483_647;

    /**
     * Composes policy persistence, session validation, and trusted bootstrap configuration.
     *
     * @param PDO $connection
     * @param AdministrationRepository $repository
     * @param IdentityRepository $identities
     * @param ProviderConnectionRepository $connections
     * @param BrowserSessionService $sessions
     * @param SecurityConfiguration $configuration
     */
    public function __construct(
        /** Owns atomic instance mutations and audit events. */
        private PDO                          $connection,
        /** Stores grants and locks the instance settings row. */
        private AdministrationRepository     $repository,
        /** Resolves provider-qualified identity records. */
        private IdentityRepository           $identities,
        /** Stores allowed connection metadata. */
        private ProviderConnectionRepository $connections,
        /** Rechecks current browser credentials for protected actions. */
        private BrowserSessionService        $sessions,
        /** Supplies the fixed bootstrap identity and external registrations. */
        private SecurityConfiguration        $configuration,
    ) {}

    /**
     * Acquires the common instance mutex before login takes user locks.
     *
     * @return void
     */
    public function lockSettings(): void
    {
        $this->repository->lockSettings();
    }

    /**
     * Grants bootstrap access only when a verified local identity exactly matches operator configuration.
     *
     * @param int $userId
     * @return void
     */
    public function ensureBootstrapAdministrator(int $userId): void
    {
        if ($this->isBootstrapIdentity($userId) && !$this->isInstanceAdministrator($userId)) {
            $this->repository->grantAdministrator($userId, null);
            $this->repository->recordEvent($userId, 'bootstrap_administrator_granted');
        }
    }

    /**
     * Records verified provider sign-in within its identity/session transaction.
     *
     * @param int $userId
     * @return void
     */
    public function recordSignIn(int $userId): void
    {
        $this->repository->recordEvent($userId, 'provider_sign_in');
    }

    /**
     * Checks current administration, independent of usernames or repository contributor roles.
     *
     * @param int $userId
     * @return bool
     */
    public function isInstanceAdministrator(int $userId): bool
    {
        return $this->repository->isInstanceAdministrator($userId);
    }

    /**
     * Rejects ordinary contributors and revoked/expired browser sessions.
     *
     * @param BrowserSession $session
     * @return void
     * @throws AccountException
     */
    public function requireInstanceAdministrator(BrowserSession $session): void
    {
        $this->sessions->requireActiveSession($session);
        if (!$this->isInstanceAdministrator($session->userId)) {
            throw new AccountException('Instance administration is required.');
        }
    }

    /**
     * Registers or enables/disables a connection using existing external secrets, never submitted arbitrary URLs.
     *
     * @param BrowserSession $session
     * @param string $reference
     * @param string $name
     * @param bool $isEnabled
     * @return int
     */
    public function saveConnection(BrowserSession $session, string $reference, string $name, bool $isEnabled): int
    {
        $registration = $this->configuration->connections[$reference] ?? null;
        if ($registration === null || trim($name) === '') {
            throw new AccountException('Choose a configured registration and a connection name.', 400);
        }
        $id = 0;
        $this->executeMutation($session,
            /**
             * Saves permitted metadata under the instance mutex and records the actor.
             *
             * @param BrowserSession $currentSession
             * @return void
             */
            function (BrowserSession $currentSession) use ($reference, $name, $isEnabled, $registration, &$id): void {
                if (!$isEnabled && $reference === $this->configuration->bootstrapConnection) {
                    throw new AccountException('The configured bootstrap connection cannot be disabled.');
                }
                $id = $this->connections->saveConnection($reference, $registration['kind'], $registration['serverUrl'], trim($name), $isEnabled);
                $this->repository->recordEvent($currentSession->userId, $isEnabled ? 'provider_connection_enabled' : 'provider_connection_disabled');
            },
        );
        return $id;
    }

    /**
     * Grants or revokes an existing identity with fresh reauthentication and bootstrap/last-admin protection.
     *
     * @param BrowserSession $session
     * @param int $targetUserId
     * @param bool $isAdministrator
     * @return void
     */
    public function setAdministrator(BrowserSession $session, int $targetUserId, bool $isAdministrator): void
    {
        $this->executeMutation($session,
            /**
             * Rechecks protected targets and current grant count under the serialized instance lock.
             *
             * @param BrowserSession $currentSession
             * @return void
             */
            function (BrowserSession $currentSession) use ($targetUserId, $isAdministrator): void {
                if ($this->identities->findUser($targetUserId) === null) {
                    throw new AccountException('User not found.', 404);
                }
                if ($isAdministrator) {
                    $this->repository->grantAdministrator($targetUserId, $currentSession->userId);
                } else {
                    if ($this->isBootstrapIdentity($targetUserId) || ($this->isInstanceAdministrator($targetUserId) && $this->repository->countAdministrators() <= 1)) {
                        throw new AccountException('The bootstrap or last administrator cannot be removed.');
                    }
                    $this->repository->revokeAdministrator($targetUserId);
                }
                $this->repository->recordEvent($currentSession->userId, $isAdministrator ? 'instance_administrator_granted' : 'instance_administrator_revoked');
            }, true,
        );
    }

    /**
     * Updates policy for future sessions; existing sessions keep their original limits.
     *
     * @param BrowserSession $session
     * @param int $idleMinutes
     * @param int $absoluteMinutes
     * @return void
     */
    public function saveSessionLimits(BrowserSession $session, int $idleMinutes, int $absoluteMinutes): void
    {
        if ($idleMinutes <= 0 || $absoluteMinutes <= 0 || $idleMinutes > self::MAX_MINUTES || $absoluteMinutes > self::MAX_MINUTES) {
            throw new AccountException('Session limits must be positive integer minutes.', 400);
        }
        $this->executeMutation($session,
            /**
             * Saves limits and audit together without updating existing sessions.
             *
             * @param BrowserSession $currentSession
             * @return void
             */
            function (BrowserSession $currentSession) use ($idleMinutes, $absoluteMinutes): void {
                $this->repository->saveSessionLimits($idleMinutes, $absoluteMinutes);
                $this->repository->recordEvent($currentSession->userId, 'browser_session_policy_changed');
            },
        );
    }

    /**
     * Returns protected non-secret data for the instance administration page.
     *
     * @param BrowserSession $session
     * @return array
     */
    public function getAdministrationData(BrowserSession $session): array
    {
        $this->requireInstanceAdministrator($session);
        return ['settings' => $this->repository->getSettings(), 'connections' => $this->connections->findConnections(), 'users' => $this->identities->findUsers(), 'registrations' => array_keys($this->configuration->connections)];
    }

    /**
     * Serializes administrator/session checks with the mutation and its audit event.
     *
     * @param BrowserSession $session
     * @param Closure $operation
     * @param bool $requiresReauthentication
     * @return void
     */
    public function executeMutation(BrowserSession $session, Closure $operation, bool $requiresReauthentication = false): void
    {
        (new Transaction($this->connection))->execute(
            /**
             * Locks current policy/session before checking rights and running the mutation.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($session, $operation, $requiresReauthentication): void {
                $this->repository->lockSettings();
                $current = $this->sessions->requireActiveSession($session, true);
                $this->requireInstanceAdministrator($current);
                $reauthenticatedAt = $current->providerReauthenticatedAt?->getTimestamp();
                if ($requiresReauthentication && ($reauthenticatedAt === null || $reauthenticatedAt > time() || $reauthenticatedAt < time() - self::REAUTHENTICATION_SECONDS)) {
                    throw new AccountException('Sign in again before changing administrator access.');
                }
                $operation($current);
            },
        );
    }

    /**
     * Matches both the configured provider registration and immutable provider identity.
     *
     * @param int $userId
     * @return bool
     */
    private function isBootstrapIdentity(int $userId): bool
    {
        $user = $this->identities->findUser($userId);
        return $user !== null && $user['registration_reference'] === $this->configuration->bootstrapConnection && $user['provider_user_id'] === $this->configuration->bootstrapUserId;
    }
}
