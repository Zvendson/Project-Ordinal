<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Database\MigrationRunner;
use Ordinal\Model\AllocationCaller;
use Ordinal\Service\AllocationException;
use Ordinal\Service\BuildNumberService;
use Ordinal\Tests\Support\TestDatabase;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/** Verifies allocation atomicity, caller retry namespaces, and real concurrency. */
final class BuildNumberServiceTest extends TestCase
{
    /** Supplies a guarded PostgreSQL connection. */
    private PDO                $connection;
    /** Names this test's isolated schema. */
    private string             $schemaName;
    /** Uses the real transaction service. */
    private BuildNumberService $service;
    /** Identifies one build attempt. */
    private const string FIRST_REQUEST = '11111111-1111-4111-8111-111111111111';
    /** Identifies a genuinely different build attempt. */
    private const string SECOND_REQUEST = '22222222-2222-4222-8222-222222222222';
    /** Bounds the time spent waiting for both independent workers to reach the lock. */
    private const int LOCK_WAIT_SECONDS = 5;
    /** Spaces lock observations without a busy loop. */
    private const int LOCK_POLL_MICROSECONDS = 10000;

    /**
     * Creates isolated application tables and stable caller/project fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection = TestDatabase::createConnection();
        $this->schemaName = 'allocation_test_' . bin2hex(random_bytes(8));
        $this->connection->exec('CREATE SCHEMA ' . $this->schemaName);
        $this->connection->exec('SET search_path TO ' . $this->schemaName);
        (new MigrationRunner($this->connection))->applyMigrations(dirname(__DIR__, 2) . '/database/migrations');
        $this->connection->exec("INSERT INTO provider_connections (provider_kind, server_url, name) VALUES ('github', 'https://github.com', 'GitHub')");
        $this->connection->exec("INSERT INTO users (provider_connection_id, provider_user_id, display_name) VALUES (1, '1', 'First'), (1, '2', 'Second')");
        $this->connection->exec("INSERT INTO projects (provider_connection_id, provider_repository_id, name) VALUES (1, '1', 'First'), (1, '2', 'Second')");
        $this->connection->exec("INSERT INTO automation_tokens (project_id, created_by_user_id, name, secret_hash, expires_at) VALUES (1, 1, 'CI', repeat('a', 64), CURRENT_TIMESTAMP + INTERVAL '90 days')");
        $this->connection->exec('UPDATE instance_settings SET is_authentication_required = FALSE');
        $this->service = new BuildNumberService($this->connection);
    }

    /**
     * Removes only this test's isolated schema.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (isset($this->connection, $this->schemaName)) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            $this->connection->exec('DROP SCHEMA IF EXISTS ' . $this->schemaName . ' CASCADE');
        }
    }

    /**
     * Replays the original number after later allocations without incrementing again.
     *
     * @return void
     */
    public function testReplaysDelayedRequest(): void
    {
        $caller = new AllocationCaller(userId: 1);
        self::assertSame(1, $this->service->allocateBuildNumber(1, self::FIRST_REQUEST, $caller));
        self::assertSame(2, $this->service->allocateBuildNumber(1, self::SECOND_REQUEST, $caller));
        self::assertSame(1, $this->service->allocateBuildNumber(1, self::FIRST_REQUEST, $caller));
        self::assertSame(3, $this->connection->query('SELECT next_build_number FROM projects WHERE id = 1')->fetchColumn());
        self::assertSame(2, $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        self::assertSame(3, $this->connection->query('SELECT count(*) FROM audit_events')->fetchColumn());
    }

    /**
     * Separates users, automation, anonymous requests, and projects using the same UUID.
     *
     * @return void
     */
    public function testSeparatesCallerNamespaces(): void
    {
        foreach ([new AllocationCaller(userId: 1), new AllocationCaller(userId: 2),
            new AllocationCaller(automationTokenId: 1, automationSecretHash: str_repeat('a', 64)), new AllocationCaller()] as $index => $caller) {
            self::assertSame($index + 1, $this->service->allocateBuildNumber(1, self::FIRST_REQUEST, $caller));
            self::assertSame($index + 1, $this->service->allocateBuildNumber(1, self::FIRST_REQUEST, $caller));
        }
        self::assertSame(1, $this->service->allocateBuildNumber(2, self::FIRST_REQUEST, new AllocationCaller()));
        self::assertSame(5, $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
    }

    /**
     * Allocates zero and the maximum, then rejects new requests while allowing replay.
     *
     * @return void
     */
    public function testHandlesRangeAndExhaustion(): void
    {
        $caller = new AllocationCaller();
        $this->connection->exec('UPDATE projects SET next_build_number = 0 WHERE id = 1');
        self::assertSame(0, $this->service->allocateBuildNumber(1, self::FIRST_REQUEST, $caller));
        $this->connection->exec('UPDATE projects SET next_build_number = 4294967295 WHERE id = 1');
        self::assertSame(4294967295, $this->service->allocateBuildNumber(1, self::SECOND_REQUEST, $caller));
        self::assertTrue($this->connection->query('SELECT is_exhausted FROM projects WHERE id = 1')->fetchColumn());
        self::assertSame(0, $this->service->allocateBuildNumber(1, self::FIRST_REQUEST, $caller));
        $this->expectException(AllocationException::class);
        $this->expectExceptionMessage('BUILD_COUNTER_EXHAUSTED');
        $this->service->allocateBuildNumber(1, '33333333-3333-4333-8333-333333333333', $caller);
    }

    /**
     * Rolls back the number, allocation, and history flag when the audit insert fails.
     *
     * @return void
     */
    public function testRollsBackAuditFailure(): void
    {
        $this->connection->exec("CREATE FUNCTION reject_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Test audit failure'; END; $$");
        $this->connection->exec('CREATE TRIGGER reject_audit BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION reject_audit()');
        try {
            $this->service->allocateBuildNumber(1, self::FIRST_REQUEST, new AllocationCaller());
            self::fail('Expected audit failure.');
        } catch (PDOException) {
            self::assertFalse($this->connection->inTransaction());
        }
        self::assertSame(1, $this->connection->query('SELECT next_build_number FROM projects WHERE id = 1')->fetchColumn());
        self::assertFalse($this->connection->query('SELECT has_allocated_build_number FROM projects WHERE id = 1')->fetchColumn());
        self::assertSame(0, $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        self::assertSame(0, $this->connection->query('SELECT count(*) FROM audit_events')->fetchColumn());
    }

    /**
     * Prevents direct SQL from bypassing each retry namespace's uniqueness.
     *
     * @return void
     */
    public function testEnforcesRetryUniquenessInDatabase(): void
    {
        foreach ([new AllocationCaller(), new AllocationCaller(userId: 1), new AllocationCaller(automationTokenId: 1, automationSecretHash: str_repeat('a', 64))] as $caller) {
            $id = $this->service->allocateBuildNumber(1, self::FIRST_REQUEST, $caller);
            $statement = $this->connection->prepare('INSERT INTO allocations (project_id, request_id, build_number, caller_kind, user_id, automation_token_id) VALUES (1, :requestId, :number, :kind, :userId, :tokenId)');
            try {
                $statement->execute(['requestId' => self::FIRST_REQUEST, 'number' => $id, 'kind' => $caller->getKind(), 'userId' => $caller->userId, 'tokenId' => $caller->automationTokenId]);
                self::fail('Expected duplicate retry rejection.');
            } catch (PDOException $exception) {
                self::assertSame('23505', $exception->getCode());
            }
        }
    }

    /**
     * Rejects missing or archived projects before allocating.
     *
     * @return void
     */
    public function testRejectsUnavailableProjects(): void
    {
        $this->connection->exec('UPDATE projects SET archived_at = CURRENT_TIMESTAMP WHERE id = 1');
        foreach ([1, 999] as $projectId) {
            try {
                $this->service->allocateBuildNumber($projectId, self::FIRST_REQUEST, new AllocationCaller());
                self::fail('Unavailable project must not allocate.');
            } catch (AllocationException $exception) {
                self::assertSame('PROJECT_NOT_FOUND', $exception->getMessage());
            }
        }
        self::assertSame(0, $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
    }

    /**
     * Blocks anonymous replay when authentication is enabled after an allocation.
     *
     * @return void
     */
    public function testBlocksAnonymousReplayAfterPolicyChange(): void
    {
        self::assertSame(1, $this->service->allocateBuildNumber(1, self::FIRST_REQUEST, new AllocationCaller()));
        $this->connection->exec('UPDATE instance_settings SET is_authentication_required = TRUE');
        try {
            $this->service->allocateBuildNumber(1, self::FIRST_REQUEST, new AllocationCaller());
            self::fail('Anonymous replay must respect current policy.');
        } catch (AllocationException $exception) {
            self::assertSame('INVALID_AUTHENTICATION', $exception->errorCode);
        }
        self::assertSame(1, $this->connection->query('SELECT count(*) FROM audit_events')->fetchColumn());
        self::assertSame(2, $this->connection->query('SELECT next_build_number FROM projects WHERE id = 1')->fetchColumn());
    }

    /**
     * Gives overlapping identical requests one permanent allocation and the same number.
     *
     * @return void
     */
    public function testConcurrentIdenticalRequests(): void
    {
        self::assertSame([1, 1], $this->runConcurrentRequests([self::FIRST_REQUEST, self::FIRST_REQUEST]));
        self::assertSame(1, $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        self::assertSame(2, $this->connection->query('SELECT count(*) FROM audit_events')->fetchColumn());
        self::assertSame(2, $this->connection->query('SELECT next_build_number FROM projects WHERE id = 1')->fetchColumn());
    }

    /**
     * Gives overlapping distinct requests different numbers.
     *
     * @return void
     */
    public function testConcurrentDistinctRequests(): void
    {
        $numbers = $this->runConcurrentRequests([self::FIRST_REQUEST, self::SECOND_REQUEST]);
        sort($numbers);
        self::assertSame([1, 2], $numbers);
        self::assertSame(2, $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        self::assertSame(3, $this->connection->query('SELECT next_build_number FROM projects WHERE id = 1')->fetchColumn());
    }

    /**
     * Proves separate worker connections are blocked together before releasing the project lock.
     *
     * @param array $requestIds
     * @return array
     */
    private function runConcurrentRequests(array $requestIds): array
    {
        $workers = [];
        $numbers = [];
        $this->connection->beginTransaction();
        $this->connection->query('SELECT id FROM projects WHERE id = 1 FOR UPDATE');
        try {
            foreach ($requestIds as $requestId) {
                $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';';
                $script .= 'echo json_encode(Ordinal\\Tests\\Support\\AllocationWorker::allocate('
                    . var_export($this->schemaName, true) . ', ' . var_export($requestId, true) . '), JSON_THROW_ON_ERROR);';
                $process = proc_open([PHP_BINARY, '-r', $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = ['process' => $process, 'output' => $pipes[1], 'error' => $pipes[2]];
            }
            $findBlocked = $this->connection->prepare("SELECT count(DISTINCT pid) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock'");
            $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
            do {
                $this->connection->query('SELECT pg_stat_clear_snapshot()');
                $findBlocked->execute(['name' => $this->schemaName]);
                $blockedCount = $findBlocked->fetchColumn();
                if ($blockedCount === count($requestIds)) {
                    break;
                }
                usleep(self::LOCK_POLL_MICROSECONDS);
            } while (microtime(true) < $deadline);
            self::assertSame(count($requestIds), $blockedCount, 'Both independent connections must overlap at the project lock.');
            $this->connection->commit();
            foreach ($workers as &$worker) {
                $output = stream_get_contents($worker['output']);
                $error  = stream_get_contents($worker['error']);
                fclose($worker['output']);
                fclose($worker['error']);
                $exitCode = proc_close($worker['process']);
                self::assertSame(0, $exitCode);
                self::assertSame('', $error);
                $numbers[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }
            unset($worker);
            return $numbers;
        } finally {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                    proc_close($worker['process']);
                }
                foreach (['output', 'error'] as $pipeName) {
                    if (is_resource($worker[$pipeName])) {
                        fclose($worker[$pipeName]);
                    }
                }
            }
        }
    }
}
