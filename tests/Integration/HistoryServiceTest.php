<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Model\AllocationCaller;
use Ordinal\Security\AutomationToken;
use Ordinal\Service\AccountException;
use Ordinal\Tests\Support\AccountFixture;
use PHPUnit\Framework\TestCase;

/** Verifies current access, project visibility, stable CI attribution, and safe confirmed log cleanup. */
final class HistoryServiceTest extends TestCase
{
    /** Shares only this test's guarded schema/application. */
    private AccountFixture $fixture;
    /** Names a permanent retry identity. */
    private const string REQUEST_ID = '11111111-1111-4111-8111-111111111111';

    /**
     * Creates an isolated migrated application.
     *
     * @return void
     */
    protected function setUp(): void { $this->fixture = new AccountFixture(); }
    /**
     * Cleans only the fixture schema.
     *
     * @return void
     */
    protected function tearDown(): void { $this->fixture->close(); }

    /**
     * Contributors see their own history by default and all callers only under current project policy.
     *
     * @return void
     */
    public function testEnforcesVisibilityAndPreservesNamedCiAttribution(): void
    {
        $app = $this->fixture->application;
        $admin = $this->fixture->createSession();
        $project = $app->projects->createProject($admin, 1, '77', 'Project');
        $user = $this->fixture->createSession('9');
        $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, new AllocationCaller(userId: $admin->userId));
        $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, new AllocationCaller(userId: $user->userId));
        $token = $app->automation->createToken($admin, $project, 'Release CI');
        $parsed = AutomationToken::parseToken($token['token']);
        $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, new AllocationCaller(automationTokenId: $token['id'], automationSecretHash: hash('sha256', $parsed['secret'])));
        $this->fixture->role = 30;
        $own = $app->history->getProjectHistory($user, $project);
        self::assertCount(1, $own['events']);
        self::assertSame('9', $own['events'][0]['provider_user_id']);
        self::assertCount(3, $app->history->getProjectHistory($admin, $project)['events']);
        $app->projectAdministration->saveHistoryVisibility($admin, $project, true);
        $all = $app->history->getProjectHistory($user, $project)['events'];
        self::assertCount(3, $all);
        self::assertSame('Release CI', $all[0]['automation_name']);
        self::assertSame($token['id'], $all[0]['automation_token_id']);
        self::assertStringNotContainsString($parsed['secret'], json_encode($all, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret_hash', json_encode($all, JSON_THROW_ON_ERROR));
        $app->automation->renameToken($admin, $token['id'], 'Renamed CI');
        self::assertSame('Renamed CI', $app->history->getProjectHistory($user, $project)['events'][0]['automation_name']);
        $app->projectAdministration->saveHistoryVisibility($admin, $project, false);
        self::assertCount(1, $app->history->getProjectHistory($user, $project)['events']);
        $this->fixture->role = 20;
        $this->expectException(AccountException::class);
        $app->history->getProjectHistory($user, $project);
    }

    /**
     * Project administrators see only project logs; instance administrators can see all scopes.
     *
     * @return void
     */
    public function testEnforcesAdministrativeLogScopes(): void
    {
        $app = $this->fixture->application;
        $admin = $this->fixture->createSession();
        $project = $app->projects->createProject($admin, 1, '77', 'Project');
        $other = $app->projects->createProject($admin, 1, '78', 'Other');
        $user = $this->fixture->createSession('9');
        $events = $app->history->getProjectLogs($user, $project)['events'];
        self::assertNotEmpty($events);
        foreach ($events as $event) { self::assertSame($project, $event['project_id']); }
        self::assertGreaterThan(count($events), count($app->history->getInstanceLogs($admin)['events']));
        foreach (['getInstanceLogs', 'getCleanupPreview'] as $method) {
            try {
                if ($method === 'getInstanceLogs') { $app->history->getInstanceLogs($user); }
                else { $app->history->getCleanupPreview($user, gmdate('Y-m-d')); }
                self::fail('Project administrator obtained instance audit access.');
            } catch (AccountException $exception) { self::assertSame(403, $exception->statusCode); }
        }
        $this->fixture->role = 30;
        $this->expectException(AccountException::class);
        $app->history->getProjectLogs($user, $project);
    }

    /**
     * Confirmed cleanup removes old views while preserving counters, retries, referenced identities and its own audit.
     *
     * @return void
     */
    public function testCleansAuditWithoutDeletingPermanentRetries(): void
    {
        $app = $this->fixture->application;
        $admin = $this->fixture->createSession();
        $project = $app->projects->createProject($admin, 1, '77', 'Project');
        $caller = new AllocationCaller(userId: $admin->userId);
        $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller);
        $this->fixture->connection->exec("UPDATE audit_events SET created_at = clock_timestamp() - interval '2 days'");
        $preview = $app->history->getCleanupPreview($admin, gmdate('Y-m-d'));
        self::assertGreaterThan(0, $preview['count']);
        try { $app->history->cleanupLogs($admin, gmdate('Y-m-d'), false); self::fail('Unconfirmed deletion succeeded.'); }
        catch (AccountException) { self::assertSame($preview['count'], $app->history->getCleanupPreview($admin, gmdate('Y-m-d'))['count']); }
        self::assertSame($preview['count'], $app->history->cleanupLogs($admin, gmdate('Y-m-d'), true));
        self::assertCount(0, $app->history->getProjectHistory($admin, $project)['events']);
        self::assertSame('audit_logs_deleted', $app->history->getInstanceLogs($admin)['events'][0]['action']);
        self::assertSame(1, (int) $this->fixture->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        self::assertSame(2, $app->projectAdministration->getNextBuildNumber($admin, $project)['nextBuildNumber']);
        self::assertSame(1, $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        self::assertSame(2, $app->projectAdministration->getNextBuildNumber($admin, $project)['nextBuildNumber']);
    }

    /**
     * Invalid dates and future cutoffs cannot cause destructive cleanup.
     *
     * @return void
     */
    public function testRejectsInvalidCleanupDates(): void
    {
        $app = $this->fixture->application;
        $admin = $this->fixture->createSession();
        foreach (['2026-02-30', 'tomorrow', '2099-01-01', '2026-01-01T00:00:00Z', '', "\0", '0000-01-01'] as $date) {
            try { $app->history->getCleanupPreview($admin, $date); self::fail('Invalid date accepted.'); }
            catch (AccountException $exception) { self::assertSame(400, $exception->statusCode); }
        }
    }

    /**
     * Failed cleanup auditing rolls back deletion as well.
     *
     * @return void
     */
    public function testRollsBackCleanupWhenItsAuditFails(): void
    {
        $app = $this->fixture->application;
        $admin = $this->fixture->createSession();
        $app->projects->createProject($admin, 1, '77', 'Project');
        $this->fixture->connection->exec("UPDATE audit_events SET created_at = clock_timestamp() - interval '2 days'");
        $count = $app->history->getCleanupPreview($admin, gmdate('Y-m-d'))['count'];
        $this->fixture->connection->exec("ALTER TABLE audit_events ADD CONSTRAINT reject_cleanup CHECK (action <> 'audit_logs_deleted') NOT VALID");
        try { $app->history->cleanupLogs($admin, gmdate('Y-m-d'), true); self::fail('Failed audit committed deletion.'); }
        catch (\PDOException) { self::assertSame($count, $app->history->getCleanupPreview($admin, gmdate('Y-m-d'))['count']); }
    }

    /**
     * Stable ID cursors bound pages without mixing scopes or repeating rows.
     *
     * @return void
     */
    public function testPaginatesBoundedProjectLogs(): void
    {
        $app = $this->fixture->application;
        $admin = $this->fixture->createSession();
        $project = $app->projects->createProject($admin, 1, '77', 'Project');
        $statement = $this->fixture->connection->prepare("INSERT INTO audit_events (project_id, user_id, action, outcome) SELECT :project, :user, 'counter_edited', 'success' FROM generate_series(1, 105)");
        $statement->execute(['project' => $project, 'user' => $admin->userId]);
        $first = $app->history->getProjectLogs($admin, $project)['events'];
        self::assertCount(100, $first);
        $second = $app->history->getProjectLogs($admin, $project, $first[array_key_last($first)]['id'])['events'];
        self::assertCount(6, $second);
        self::assertSame([], array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        foreach (array_merge($first, $second) as $row) { self::assertSame($project, $row['project_id']); }
    }

    /**
     * Project access cannot cross repository boundaries or survive unavailable current provider verification.
     *
     * @return void
     */
    public function testRejectsOtherRepositoriesAndProviderOutages(): void
    {
        $app = $this->fixture->application;
        $admin = $this->fixture->createSession();
        $project = $app->projects->createProject($admin, 1, '77', 'Project');
        $other = $app->projects->createProject($admin, 1, '78', 'Other');
        $user = $this->fixture->createSession('9');
        $this->fixture->repositoryRoles = ['77' => 40, '78' => 20];
        self::assertNotEmpty($app->history->getProjectLogs($user, $project)['events']);
        foreach (['getProjectHistory', 'getProjectLogs'] as $method) {
            try { $app->history->$method($user, $other); self::fail('Project access leaked across repository scope.'); }
            catch (AccountException $exception) { self::assertSame(403, $exception->statusCode); }
        }
        $this->fixture->isProviderUnavailable = true;
        self::assertNotEmpty($app->history->getInstanceLogs($admin)['events']);
        $this->expectException(\Ordinal\Provider\ProviderUnavailableException::class);
        $app->history->getProjectHistory($user, $project);
    }
}
