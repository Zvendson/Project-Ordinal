<?php

declare(strict_types=1);

namespace Ordinal\Service;

use DateTimeImmutable;
use DateTimeZone;
use Ordinal\Model\BrowserSession;
use Ordinal\Repository\AuditRepository;
use Ordinal\Repository\ProjectRepository;

/** Enforces current history/log visibility and confirmed audit cleanup independently of permanent retry state. */
final readonly class HistoryService
{
    /**
     * Composes current provider/local access and audit-only persistence.
     *
     * @param AuditRepository $repository
     * @param ProjectRepository $projectRepository
     * @param ProjectService $projects
     * @param AdministrationService $administration
     */
    public function __construct(
        /** Reads bounded secret-free events. */
        private AuditRepository       $repository,
        /** Reads the current project visibility flag. */
        private ProjectRepository     $projectRepository,
        /** Verifies current repository access. */
        private ProjectService        $projects,
        /** Protects instance log views and cleanup transactions. */
        private AdministrationService $administration,
    ) {}

    /**
     * Returns own build history unless current project policy or administration permits other callers.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @param ?int $beforeId
     * @return array
     */
    public function getProjectHistory(BrowserSession $session, int $projectId, ?int $beforeId = null): array
    {
        $permissions = $this->projects->checkPermissions($session, $projectId, true);
        if (!$permissions->canAllocateBuildNumber) { throw new AccountException('Current repository write access is required.'); }
        $project = $this->projectRepository->findProject($projectId);
        $userId = $permissions->canAdministerProject ? null : $session->userId;
        return ['project' => $project, 'events' => $this->repository->findEvents($projectId, $userId, true, $beforeId)];
    }

    /**
     * Restricts project administrative/security logs to its current administrators.
     *
     * @param BrowserSession $session
     * @param int $projectId
     * @param ?int $beforeId
     * @return array
     */
    public function getProjectLogs(BrowserSession $session, int $projectId, ?int $beforeId = null): array
    {
        if (!$this->projects->checkPermissions($session, $projectId, true)->canAdministerProject) { throw new AccountException('Project administration is required.'); }
        return ['project' => $this->projectRepository->findProject($projectId), 'events' => $this->repository->findEvents($projectId, null, false, $beforeId)];
    }

    /**
     * Restricts cross-project/security events to active instance administrators.
     *
     * @param BrowserSession $session
     * @param ?int $beforeId
     * @return array
     */
    public function getInstanceLogs(BrowserSession $session, ?int $beforeId = null): array
    {
        $this->administration->requireInstanceAdministrator($session);
        return ['project' => null, 'events' => $this->repository->findEvents(null, null, false, $beforeId)];
    }

    /**
     * Shows the exact UTC cutoff and current deletion count before explicit confirmation.
     *
     * @param BrowserSession $session
     * @param string $beforeDate
     * @return array
     */
    public function getCleanupPreview(BrowserSession $session, string $beforeDate): array
    {
        $this->administration->requireInstanceAdministrator($session);
        $cutoff = $this->createCutoff($beforeDate);
        return ['beforeDate' => $beforeDate, 'cutoff' => $cutoff, 'count' => $this->repository->countBefore($cutoff)];
    }

    /**
     * Deletes only old audit rows and records the actual count in the same protected transaction.
     *
     * @param BrowserSession $session
     * @param string $beforeDate
     * @param bool $isConfirmed
     * @return int
     */
    public function cleanupLogs(BrowserSession $session, string $beforeDate, bool $isConfirmed): int
    {
        $cutoff = $this->createCutoff($beforeDate);
        if (!$isConfirmed) { throw new AccountException('Confirm deletion of logs before the selected UTC date.', 400); }
        $count = 0;
        $this->administration->executeMutation($session,
            /**
             * Commits deletion and its surviving cleanup audit atomically.
             *
             * @param BrowserSession $current
             * @return void
             */
            function (BrowserSession $current) use ($cutoff, $beforeDate, &$count): void {
                $count = $this->repository->deleteBefore($cutoff);
                $this->repository->recordEvent($current->userId, null, 'audit_logs_deleted', ['beforeDateUtc' => $beforeDate, 'deletedCount' => $count]);
            },
        );
        return $count;
    }

    /**
     * Requires a real nonfuture calendar date and makes its midnight boundary explicit in UTC.
     *
     * @param string $beforeDate
     * @return string
     */
    private function createCutoff(string $beforeDate): string
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $beforeDate) !== 1 || str_starts_with($beforeDate, '0000-')) {
            throw new AccountException('Choose a valid date no later than today in UTC.', 400);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $beforeDate, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $beforeDate || $date > new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new AccountException('Choose a valid date no later than today in UTC.', 400);
        }
        return $date->format('Y-m-d\TH:i:sP');
    }
}
