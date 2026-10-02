<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Security\ProjectToken;
use Ordinal\Tests\Support\ManagementFixture;
use PHPUnit\Framework\TestCase;

/** Verifies token expiry is rechecked after a real concurrent project lock wait. */
final class ProjectTokenServiceTest extends TestCase
{
    /**
     * Rejects a token that expires while its allocation waits behind another transaction.
     *
     * @return void
     */
    public function testRechecksExpiryAfterProjectLockWait(): void
    {
        $fixture = new ManagementFixture();
        $process = null;
        $pipes = [];
        try {
            $project = $fixture->application->projects->createProject('Project');
            $token = $fixture->application->tokens->createToken($project, 'CI');
            $parsed = ProjectToken::parseToken($token['token']);
            $fixture->connection->exec("UPDATE automation_tokens SET expires_at = clock_timestamp() + INTERVAL '3 seconds'");
            $fixture->connection->beginTransaction();
            $fixture->connection->exec('SELECT id FROM projects WHERE id = ' . $project . ' FOR UPDATE');
            $code = 'require $argv[1]; echo Ordinal\\Tests\\Support\\TokenAllocationWorker::allocate($argv[2], (int) $argv[3], $argv[4], $argv[5]);';
            $process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $fixture->schemaName, (string) $token['id'], hash('sha256', $parsed['secret']), '11111111-1111-4111-8111-111111111111'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, getenv());
            self::assertIsResource($process);
            fclose($pipes[0]);
            $deadline = microtime(true) + 2;
            $isWaiting = false;
            do {
                $fixture->connection->query('SELECT pg_stat_clear_snapshot()');
                $statement = $fixture->connection->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock'");
                $statement->execute(['name' => $fixture->schemaName]);
                $isWaiting = $statement->fetchColumn() > 0;
                if (!$isWaiting) { usleep(10000); }
            } while (!$isWaiting && microtime(true) < $deadline);
            self::assertTrue($isWaiting, 'An independent worker must reach the real project lock.');
            usleep(3100000);
            $fixture->connection->commit();
            $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $pipes = [];
            self::assertSame(0, proc_close($process)); $process = null;
            self::assertSame('', $error);
            self::assertSame('INVALID_AUTHENTICATION', $output);
            self::assertSame(0, $fixture->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        } finally {
            if ($fixture->connection->inTransaction()) { $fixture->connection->rollBack(); }
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            $fixture->close();
        }
    }
}
