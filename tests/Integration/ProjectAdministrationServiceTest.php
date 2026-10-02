<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Model\AllocationCaller;
use Ordinal\Service\AccountException;
use Ordinal\Tests\Support\AccountFixture;
use PDOException;
use PHPUnit\Framework\TestCase;

/** Exercises counter editing, confirmed reset, active roles, archival and atomic audit persistence. */
final class ProjectAdministrationServiceTest extends TestCase
{
    /** Shares guarded account/provider setup. */
    private AccountFixture $fixture;

    /**
     * Creates an isolated migrated application.
     *
     * @return void
     */
    protected function setUp(): void { $this->fixture = new AccountFixture(); }

    /**
     * Drops only the fixture schema.
     *
     * @return void
     */
    protected function tearDown(): void { $this->fixture->close(); }

    /**
     * Previews without allocating and allows free initial edits, then only increases.
     *
     * @return void
     */
    public function testPreviewsAndEditsWithoutConsumingNumbers(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        self::assertSame(['projectId' => $project, 'nextBuildNumber' => 1, 'isExhausted' => false], $app->projectAdministration->getNextBuildNumber($session, $project));
        $app->projectAdministration->editCounter($session, $project, 8);
        $app->projectAdministration->editCounter($session, $project, 0);
        self::assertSame(0, (int) $this->fixture->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        self::assertSame(0, $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, new AllocationCaller(userId: $session->userId)));
        $app->projectAdministration->editCounter($session, $project, 10);
        foreach ([0, 10, -1, 4294967296] as $number) {
            try { $app->projectAdministration->editCounter($session, $project, $number); self::fail('Invalid ordinary edit succeeded.'); }
            catch (AccountException) { self::assertSame(10, $app->projectAdministration->getNextBuildNumber($session, $project)['nextBuildNumber']); }
        }
    }

    /** Names a permanent retry identity. */
    private const string REQUEST_ID = '11111111-1111-4111-8111-111111111111';

    /**
     * Resets clear exhaustion without deleting history/retries or the ever-allocated flag.
     *
     * @return void
     */
    public function testConfirmsResetAndPreservesMaximumReplay(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        $app->projectAdministration->editCounter($session, $project, 4294967295);
        $caller = new AllocationCaller(userId: $session->userId);
        self::assertSame(4294967295, $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        self::assertTrue($app->projectAdministration->getNextBuildNumber($session, $project)['isExhausted']);
        foreach ([['wrong', true], ['Project', false]] as [$name, $isConfirmed]) {
            try { $app->projectAdministration->resetCounter($session, $project, 0, $name, $isConfirmed); self::fail('Unconfirmed reset succeeded.'); }
            catch (AccountException) { self::assertTrue($app->projectAdministration->getNextBuildNumber($session, $project)['isExhausted']); }
        }
        $app->projectAdministration->resetCounter($session, $project, 0, 'Project', true);
        self::assertFalse($app->projectAdministration->getNextBuildNumber($session, $project)['isExhausted']);
        self::assertTrue($this->fixture->connection->query('SELECT has_allocated_build_number FROM projects')->fetchColumn());
        self::assertSame(4294967295, $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        self::assertSame(0, $app->buildNumbers->allocateBuildNumber($project, '22222222-2222-4222-8222-222222222222', $caller));
        $details = json_decode($this->fixture->connection->query("SELECT details FROM audit_events WHERE action = 'counter_reset'")->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        ksort($details);
        self::assertSame(['newNextBuildNumber' => 0, 'oldNextBuildNumber' => 4294967295, 'wasExhausted' => true], $details);
    }

    /**
     * Checks current repository administration and persisted reauthentication instead of trusting supplied models.
     *
     * @return void
     */
    public function testRequiresCurrentAdministrationAndRecentProviderSignIn(): void
    {
        $app = $this->fixture->application;
        $admin = $this->fixture->createSession();
        $project = $app->projects->createProject($admin, 1, '77', 'Project');
        $contributor = $this->fixture->createSession('9');
        $this->fixture->role = 30;
        try { $app->projectAdministration->getNextBuildNumber($contributor, $project); self::fail('Contributor preview succeeded.'); }
        catch (AccountException $exception) { self::assertSame(403, $exception->statusCode); }
        $this->fixture->role = 40;
        $app->projectAdministration->editCounter($contributor, $project, 12);
        $this->fixture->connection->exec("UPDATE browser_sessions SET provider_reauthenticated_at = clock_timestamp() - interval '6 minutes'");
        try { $app->projectAdministration->resetCounter($contributor, $project, 0, 'Project', true); self::fail('Stale reset succeeded.'); }
        catch (AccountException) { self::assertSame(12, $app->projectAdministration->getNextBuildNumber($admin, $project)['nextBuildNumber']); }
    }

    /**
     * Rolls back a counter change if its successful audit cannot be persisted.
     *
     * @return void
     */
    public function testRollsBackCounterWhenAuditFails(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        $this->fixture->connection->exec("ALTER TABLE audit_events ADD CONSTRAINT reject_counter CHECK (action NOT IN ('counter_edited', 'counter_reset')) NOT VALID");
        foreach ([false, true] as $isReset) {
            try {
                if ($isReset) { $app->projectAdministration->resetCounter($session, $project, 0, 'Project', true); }
                else { $app->projectAdministration->editCounter($session, $project, 42); }
                self::fail('Audit failure committed a counter change.');
            } catch (PDOException) { self::assertSame(1, $app->projectAdministration->getNextBuildNumber($session, $project)['nextBuildNumber']); }
        }
    }

    /**
     * Archival and reactivation retain counters, identities and permanent retries.
     *
     * @return void
     */
    public function testArchivesAndReactivatesWithoutDeletingRecords(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        $caller = new AllocationCaller(userId: $session->userId);
        $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller);
        $app->projectAdministration->setArchived($session, $project, true);
        try { $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller); self::fail('Archived replay succeeded.'); }
        catch (\Ordinal\Service\AllocationException $exception) { self::assertSame('PROJECT_NOT_FOUND', $exception->errorCode); }
        $app->projectAdministration->setArchived($session, $project, false);
        self::assertSame(1, $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        self::assertSame(2, $app->projectAdministration->getNextBuildNumber($session, $project)['nextBuildNumber']);
    }

    /**
     * Independent reset/allocation requests overlap at the same row lock and preserve pre-reset retry identity.
     *
     * @return void
     */
    public function testSerializesConcurrentResetAndAllocation(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        $caller = new AllocationCaller(userId: $session->userId);
        $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller);
        $scripts = [$this->createWorkerScript('reset', $session), $this->createWorkerScript('allocate', $session)];
        $this->fixture->connection->beginTransaction();
        (new \Ordinal\Repository\AllocationRepository($this->fixture->connection))->lockProject($project);
        $outputs = \Ordinal\Tests\Support\ConcurrentAllocations::run($this->fixture->connection, $this->fixture->schemaName, $scripts,
            /**
             * Releases the artificial project lock only after both independent workers are observed waiting.
             *
             * @return void
             */
            static function (): void {},
        );
        self::assertSame('RESET', $outputs[0]);
        self::assertContains($outputs[1], ['0', '2']);
        $next = $app->projectAdministration->getNextBuildNumber($session, $project)['nextBuildNumber'];
        self::assertSame($outputs[1] === '0' ? 1 : 0, $next);
        self::assertSame(1, $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        self::assertSame($next, $app->projectAdministration->getNextBuildNumber($session, $project)['nextBuildNumber']);
        self::assertSame(2, (int) $this->fixture->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
    }

    /**
     * A reset cannot retain five-minute approval while it waits past that deadline for the project lock.
     *
     * @return void
     */
    public function testRechecksSignInAgeAfterProjectLockWait(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        $this->fixture->connection->exec("UPDATE browser_sessions SET provider_reauthenticated_at = clock_timestamp() - interval '299 seconds'");
        $this->fixture->connection->beginTransaction();
        (new \Ordinal\Repository\AllocationRepository($this->fixture->connection))->lockProject($project);
        $outputs = \Ordinal\Tests\Support\ConcurrentAllocations::run($this->fixture->connection, $this->fixture->schemaName, [$this->createWorkerScript('reset', $session)],
            /**
             * Lets the persisted provider sign-in age past five minutes while the reset is blocked.
             *
             * @return void
             */
            function (): void { $this->fixture->connection->query('SELECT pg_sleep(2.2)'); },
        );
        self::assertSame(['DENIED'], $outputs);
        self::assertSame(1, $app->projectAdministration->getNextBuildNumber($session, $project)['nextBuildNumber']);
        self::assertSame(0, (int) $this->fixture->connection->query("SELECT count(*) FROM audit_events WHERE action = 'counter_reset'")->fetchColumn());
    }

    /**
     * An ordinary edit cannot use a browser session which expires during its project lock wait.
     *
     * @return void
     */
    public function testRechecksBrowserExpirationAfterProjectLockWait(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        $this->fixture->connection->exec("UPDATE browser_sessions SET expires_at = clock_timestamp() + interval '2 seconds'");
        $this->fixture->connection->beginTransaction();
        (new \Ordinal\Repository\AllocationRepository($this->fixture->connection))->lockProject($project);
        $outputs = \Ordinal\Tests\Support\ConcurrentAllocations::run($this->fixture->connection, $this->fixture->schemaName, [$this->createWorkerScript('edit', $session)],
            /**
             * Lets absolute browser expiry pass while the ordinary edit waits.
             *
             * @return void
             */
            function (): void { $this->fixture->connection->query('SELECT pg_sleep(2.2)'); },
        );
        self::assertSame(['DENIED'], $outputs);
        self::assertSame(1, (int) $this->fixture->connection->query('SELECT next_build_number FROM projects')->fetchColumn());
    }

    /**
     * Confirmed reset can reuse a number without overwriting either allocation's permanent mapping.
     *
     * @return void
     */
    public function testRetainsSeparateAllocationsWhenNumbersAreReused(): void
    {
        $app = $this->fixture->application;
        $session = $this->fixture->createSession();
        $project = $app->projects->createProject($session, 1, '77', 'Project');
        $caller = new AllocationCaller(userId: $session->userId);
        self::assertSame(1, $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        $app->projectAdministration->resetCounter($session, $project, 1, 'Project', true);
        self::assertSame(1, $app->buildNumbers->allocateBuildNumber($project, '22222222-2222-4222-8222-222222222222', $caller));
        self::assertSame(1, $app->buildNumbers->allocateBuildNumber($project, self::REQUEST_ID, $caller));
        self::assertSame(2, (int) $this->fixture->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        self::assertSame(2, $app->projectAdministration->getNextBuildNumber($session, $project)['nextBuildNumber']);
    }

    /**
     * Creates a trusted independent-worker program without browser/provider secrets.
     *
     * @param string $operation
     * @param \Ordinal\Model\BrowserSession $session
     * @return string
     */
    private function createWorkerScript(string $operation, \Ordinal\Model\BrowserSession $session): string
    {
        return 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; echo \\Ordinal\\Tests\\Support\\ProjectAdministrationWorker::run('
            . var_export($this->fixture->schemaName, true) . ', ' . var_export($operation, true) . ', ' . $session->userId . ', ' . $session->id . ', ' . var_export($session->csrfToken, true) . ');';
    }
}
