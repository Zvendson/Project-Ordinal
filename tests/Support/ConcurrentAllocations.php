<?php

declare(strict_types=1);

namespace Ordinal\Tests\Support;

use Closure;
use PDO;
use RuntimeException;

/** Observes independent allocation workers waiting at a held PostgreSQL lock before releasing a mutation. */
final class ConcurrentAllocations
{
    /** Bounds lock observation and worker completion. */
    private const int WAIT_SECONDS = 8;
    /** Avoids busy polling database/process state. */
    private const int POLL_MICROSECONDS = 20_000;

    /**
     * Runs workers under a caller-held lock and commits the coordinated mutation before collecting results.
     *
     * @param PDO $connection
     * @param string $applicationName
     * @param array $scripts
     * @param Closure $beforeRelease
     * @return array
     */
    public static function run(PDO $connection, string $applicationName, array $scripts, Closure $beforeRelease): array
    {
        if (!$connection->inTransaction() || $scripts === []) {
            throw new RuntimeException('Concurrency observation requires a held transaction and worker scripts.');
        }
        $workers = [];
        $outputs = [];
        try {
            foreach ($scripts as $script) {
                $process = proc_open([PHP_BINARY, '-r', $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException('Could not launch an allocation worker.');
                }
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $workers[] = ['process' => $process, 'output' => $pipes[1], 'error' => $pipes[2]];
            }
            $statement = $connection->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name = :name AND wait_event_type = 'Lock'");
            $deadline = microtime(true) + self::WAIT_SECONDS;
            do {
                $connection->query('SELECT pg_stat_clear_snapshot()');
                $statement->execute(['name' => $applicationName]);
                $waiting = (int) $statement->fetchColumn();
                if ($waiting === count($scripts)) {
                    break;
                }
                usleep(self::POLL_MICROSECONDS);
            } while (microtime(true) < $deadline);
            if ($waiting !== count($scripts)) {
                throw new RuntimeException('Independent allocation workers did not overlap at the held lock.');
            }
            $beforeRelease();
            $connection->commit();
            foreach ($workers as &$worker) {
                $output = '';
                $error = '';
                $deadline = microtime(true) + self::WAIT_SECONDS;
                do {
                    $output .= stream_get_contents($worker['output']);
                    $error .= stream_get_contents($worker['error']);
                    $status = proc_get_status($worker['process']);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(self::POLL_MICROSECONDS);
                } while (microtime(true) < $deadline);
                if ($status['running']) {
                    throw new RuntimeException('Allocation worker exceeded its deadline.');
                }
                $output .= stream_get_contents($worker['output']);
                $error .= stream_get_contents($worker['error']);
                fclose($worker['output']);
                fclose($worker['error']);
                $exitCode = proc_close($worker['process']);
                $worker['process'] = null;
                if ($exitCode !== 0) {
                    throw new RuntimeException('Allocation worker failed: ' . $error);
                }
                $outputs[] = $output;
            }
            unset($worker);
            return $outputs;
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                    foreach (['output', 'error'] as $pipe) {
                        if (is_resource($worker[$pipe])) {
                            fclose($worker[$pipe]);
                        }
                    }
                    proc_close($worker['process']);
                }
            }
        }
    }
}
