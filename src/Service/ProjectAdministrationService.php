<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Closure;
use Ordinal\Database\Transaction;
use Ordinal\Model\BrowserSession;
use Ordinal\Repository\AllocationRepository;
use Ordinal\Repository\AuditRepository;
use Ordinal\Repository\ProjectRepository;
use PDO;

/** Serializes protected counter, visibility and archive changes with allocation without destroying retries. */
final readonly class ProjectAdministrationService
{
    /**
     * Composes current-role checks, project locking, and atomic audits.
     *
     * @param PDO $connection
     * @param ProjectRepository $repository
     * @param ProjectService $projects
     * @param BrowserSessionService $sessions
     * @param AdministrationService $administration
     * @param AuditRepository $audit
     */
    public function __construct(
        /** Owns mutation transactions. */
        private PDO                      $connection,
        /** Saves only the intended project fields. */
        private ProjectRepository        $repository,
        /** Verifies current provider-qualified project access. */
        private ProjectService           $projects,
        /** Rechecks persisted browser and sign-in time. */
        private BrowserSessionService    $sessions,
        /** Serializes local administrator grant changes. */
        private AdministrationService    $administration,
        /** Records committed mutations with their actor. */
        private AuditRepository          $audit,
    ) {}

    /**
     * Reads the next number without reserving or consuming it.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @return array
     */
    public function getNextBuildNumber(BrowserSession $session, int $projectId): array
    {
        $this->requireAdministrator($session, $projectId);
        $project = $this->repository->findProject($projectId);
        return ['projectId' => $projectId, 'nextBuildNumber' => (int) $project['next_build_number'], 'isExhausted' => $project['is_exhausted']];
    }

    /**
     * Allows free initial edits and strictly increasing ordinary edits after any allocation.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @param int $number
     * @return void
     */
    public function editCounter(BrowserSession $session, int $projectId, int $number): void
    {
        $this->requireNumber($number);
        $this->executeMutation($session, $projectId,
            /**
             * Checks the locked permanent allocation state before editing.
             *
             * @param BrowserSession $current
             * @param array $project
             * @return void
             */
            function (BrowserSession $current, array $project) use ($projectId, $number): void {
                if ($project['has_allocated_build_number'] && ($project['is_exhausted'] || $number <= (int) $project['next_build_number'])) {
                    throw new AccountException('After allocation, ordinary edits must increase the next number. Use a confirmed reset to reuse numbers.', 409);
                }
                $this->saveCounter($current, $project, $projectId, $number, false);
            },
        );
    }

    /**
     * Requires a typed name, explicit number-reuse confirmation and recent provider sign-in.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @param int $number
     * @param string $projectName
     * @param bool $hasConfirmedReuse
     * @return void
     */
    public function resetCounter(BrowserSession $session, int $projectId, int $number, string $projectName, bool $hasConfirmedReuse): void
    {
        $this->requireNumber($number);
        $this->executeMutation($session, $projectId,
            /**
             * Validates confirmation and persisted sign-in time after the project lock wait.
             *
             * @param BrowserSession $current
             * @param array $project
             * @return void
             */
            function (BrowserSession $current, array $project) use ($projectId, $number, $projectName, $hasConfirmedReuse): void {
                $this->sessions->requireRecentProviderAuthentication($current);
                if (!$hasConfirmedReuse || $projectName !== $project['name']) {
                    throw new AccountException('Type the project name and confirm that previously allocated numbers may be reused.', 400);
                }
                $this->saveCounter($current, $project, $projectId, $number, true);
            },
        );
    }

    /**
     * Changes contributor history visibility after current administration checks.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @param bool $isVisible
     * @return void
     */
    public function saveHistoryVisibility(BrowserSession $session, int $projectId, bool $isVisible): void
    {
        $this->executeMutation($session, $projectId,
            /**
             * Commits visibility and its audit together.
             *
             * @param BrowserSession $current
             * @param array $project
             * @return void
             */
            function (BrowserSession $current, array $project) use ($projectId, $isVisible): void {
                $this->repository->saveHistoryVisibility($projectId, $isVisible);
                $this->audit->recordEvent($current->userId, $projectId, 'history_visibility_changed', ['isOtherHistoryVisible' => $isVisible]);
            },
        );
    }

    /**
     * Preserves all identity/counter/retry relationships while changing availability.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @param bool $isArchived
     * @return void
     */
    public function setArchived(BrowserSession $session, int $projectId, bool $isArchived): void
    {
        $this->executeMutation($session, $projectId,
            /**
             * Records archival/reactivation atomically.
             *
             * @param BrowserSession $current
             * @param array $project
             * @return void
             */
            function (BrowserSession $current, array $project) use ($projectId, $isArchived): void {
                $this->repository->saveArchiveState($projectId, $isArchived);
                $this->audit->recordEvent($current->userId, $projectId, $isArchived ? 'project_archived' : 'project_reactivated');
            }, true,
        );
    }

    /**
     * Rechecks browser/local rights and locks the project after current provider role verification.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @param Closure $operation
     * @param bool $canUseArchived
     * @return void
     */
    public function executeMutation(BrowserSession $session, int $projectId, Closure $operation, bool $canUseArchived = false): void
    {
        $session = $this->sessions->requireActiveSession($session);
        $hasProviderAdministration = false;
        if (!$this->administration->isInstanceAdministrator($session->userId)) {
            $this->requireAdministrator($session, $projectId, $canUseArchived);
            $hasProviderAdministration = true;
        }
        (new Transaction($this->connection))->execute(
            /**
             * Serializes settings, current session, then the same project row as allocation.
             *
             * @param PDO $connection
             * @return void
             */
            function (PDO $connection) use ($session, $projectId, $operation, $canUseArchived, $hasProviderAdministration): void {
                $this->administration->lockSettings();
                $current = $this->sessions->requireActiveSession($session, true);
                if (!$hasProviderAdministration && !$this->administration->isInstanceAdministrator($current->userId)) {
                    throw new AccountException('Project administration is required.');
                }
                $project = (new AllocationRepository($connection))->lockProject($projectId);
                if ($project === null || (!$canUseArchived && ($project['archived_at'] !== null || $project['disabled_at'] !== null))) {
                    throw new AccountException('Project not found.', 404);
                }
                $current = $this->sessions->requireActiveSession($current);
                $operation($current, $project);
            },
        );
    }

    /**
     * Requires current project/instance administration without granting contributor preview access.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @param bool $canReadInactive
     * @return void
     */
    private function requireAdministrator(BrowserSession $session, int $projectId, bool $canReadInactive = false): void
    {
        if (!$this->projects->checkPermissions($session, $projectId, $canReadInactive)->canAdministerProject) {
            throw new AccountException('Project administration is required.');
        }
    }

    /**
     * Rejects values outside uint32 without coercion.
     *
     * @param int $number
     * @return void
     */
    private function requireNumber(int $number): void
    {
        \Ordinal\Model\BuildNumber::parse((string) $number);
    }

    /**
     * Commits a locked counter change and fixed old/new-value audit fields.
     *
     * @param BrowserSession $session
     * @param array $project
     * @param int $projectId
     * @param int $number
     * @param bool $isReset
     * @return void
     */
    private function saveCounter(BrowserSession $session, array $project, int $projectId, int $number, bool $isReset): void
    {
        $this->repository->saveCounter($projectId, $number);
        $this->audit->recordEvent($session->userId, $projectId, $isReset ? 'counter_reset' : 'counter_edited',
            ['oldNextBuildNumber' => (int) $project['next_build_number'], 'newNextBuildNumber' => $number, 'wasExhausted' => $project['is_exhausted']]);
    }
}
