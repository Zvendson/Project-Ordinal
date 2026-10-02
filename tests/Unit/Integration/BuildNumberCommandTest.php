<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;

/** Verifies the command fails cleanly without leaking secret-store values or producing fallback numbers. */
final class BuildNumberCommandTest extends TestCase
{
    /**
     * Missing/insecure configuration exits nonzero, prints no number, and never displays the token.
     *
     * @return void
     */
    public function testStopsOnInvalidConfigurationWithoutLeakingToken(): void
    {
        foreach (['', 'http://ordinal.example'] as $url) {
            $environment = getenv();
            $environment['ORDINAL_URL'] = $url;
            $environment['ORDINAL_PROJECT_ID'] = '1';
            $environment['ORDINAL_BUILD_ATTEMPT'] = 'build-42';
            $environment['ORDINAL_TOKEN'] = 'private-command-test-token';
            $environment['ORDINAL_REQUEST_DIRECTORY'] = dirname(__DIR__, 3) . '/.local/unused-command-test-state';
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/bin/request-build-number.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $environment);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(1, proc_close($process));
            self::assertSame('', $output);
            self::assertNotSame('', $error);
            self::assertStringNotContainsString('private-command-test-token', $error);
            self::assertDirectoryDoesNotExist($environment['ORDINAL_REQUEST_DIRECTORY']);
        }
    }

    /**
     * Runs the integration command without Composer or any database configuration/extensions.
     *
     * @return void
     */
    public function testRunsStandaloneWithoutComposer(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $directory = $projectRoot . '/.local/standalone_command_test_' . bin2hex(random_bytes(8));
        $paths = ['bin/request-build-number.php', 'integrations/autoload.php', 'src/Integration/BuildNumberClient.php', 'src/Integration/BuildRequestStore.php', 'src/Integration/BuildIntegrationException.php', 'src/Integration/BuildTransportException.php', 'src/Integration/BuildNumberTransport.php', 'src/Integration/CurlBuildNumberTransport.php', 'src/Model/RequestId.php', 'src/Model/ProviderConfiguration.php', 'src/Http/HttpResponse.php'];
        $directories = [];
        try {
            foreach ($paths as $path) {
                if (!is_file($projectRoot . '/' . $path)) {
                    continue;
                }
                $targetDirectory = dirname($directory . '/' . $path);
                if (!is_dir($targetDirectory)) {
                    mkdir($targetDirectory, 0700, true);
                    $directories[] = $targetDirectory;
                }
                copy($projectRoot . '/' . $path, $directory . '/' . $path);
            }
            $environment = getenv();
            $environment['ORDINAL_URL'] = 'http://ordinal.example';
            $environment['ORDINAL_PROJECT_ID'] = '1';
            $environment['ORDINAL_BUILD_ATTEMPT'] = 'build-42';
            $process = proc_open([PHP_BINARY, $directory . '/bin/request-build-number.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $environment);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(1, proc_close($process));
            self::assertSame('', $output);
            self::assertStringContainsString('HTTPS', $error);
            self::assertStringNotContainsString('Fatal error', $error);
        } finally {
            foreach ($paths as $path) {
                if (is_file($directory . '/' . $path)) {
                    unlink($directory . '/' . $path);
                }
            }
            foreach (array_reverse($directories) as $path) {
                rmdir($path);
            }
            if (is_dir($directory . '/src')) {
                rmdir($directory . '/src');
            }
            rmdir($directory);
        }
    }
}
