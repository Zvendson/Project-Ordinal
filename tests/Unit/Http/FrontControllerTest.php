<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/** Verifies that unexpected web-entry failures do not expose exception details. */
final class FrontControllerTest extends TestCase
{
    /**
     * Returns a generic browser failure instead of private diagnostics.
     *
     * @return void
     */
    public function testHidesBrowserFailureDetails(): void
    {
        [$body, $status] = $this->runFailedRequest('/');
        self::assertSame('500', $status);
        self::assertSame('An unexpected error occurred.', $body);
    }

    /**
     * Returns a structured API failure without leaking internal values.
     *
     * @return void
     */
    public function testHidesApiFailureDetails(): void
    {
        [$body, $status] = $this->runFailedRequest('/api/projects');
        self::assertSame('500', $status);
        self::assertSame(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.']],
            json_decode($body, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Executes the entry point in a separate PHP process with a failing router autoloader.
     *
     * @param string $requestTarget
     * @return array
     */
    private function runFailedRequest(string $requestTarget): array
    {
        $projectPath = dirname(__DIR__, 3);
        $script = 'require ' . var_export($projectPath . '/vendor/autoload.php', true) . ';';
        $script .= <<<'PHP'
spl_autoload_register(
    /**
     * Simulates a private application failure.
     *
     * @param string $className
     * @return void
     */
    static function (string $className): void {
        if ($className === 'Ordinal\\Http\\Router') {
            throw new RuntimeException('private-password=do-not-expose');
        }
    }, true, true,
);
$_SERVER['REQUEST_METHOD'] = 'GET';
PHP;
        $script .= '$_SERVER["REQUEST_URI"] = ' . var_export($requestTarget, true) . ';';
        $script .= 'require ' . var_export($projectPath . '/public/index.php', true) . ';';
        $script .= 'fwrite(STDERR, (string) http_response_code());';

        $process = proc_open([PHP_BINARY, '-r', $script], [
            ['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $body   = stream_get_contents($pipes[1]);
        $status = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        return [$body, $status];
    }
}
