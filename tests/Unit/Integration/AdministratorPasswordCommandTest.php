<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;

/** Verifies private password setup without command-line secrets or provider registration. */
final class AdministratorPasswordCommandTest extends TestCase
{
    /**
     * Writes only a password hash and never prints the supplied secret.
     *
     * @return void
     */
    public function testWritesPrivateHashFromStandardInput(): void
    {
        $root = dirname(__DIR__, 3);
        $directory = $root . '/.local/password_command_' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        copy($root . '/bin/set-admin-password.php', $directory . '/bin/set-admin-password.php');
        $password = 'a-password-used-only-by-the-test';
        try {
            $process = proc_open([PHP_BINARY, $directory . '/bin/set-admin-password.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $directory);
            fwrite($pipes[0], $password . "\n"); fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
            self::assertSame('', $error);
            self::assertStringNotContainsString($password, $output);
            $configuration = require $directory . '/.ordinal/admin.php';
            self::assertTrue(password_verify($password, $configuration['passwordHash']));
            self::assertStringNotContainsString($password, file_get_contents($directory . '/.ordinal/admin.php'));
        } finally {
            foreach ([$directory . '/.ordinal/admin.php', $directory . '/bin/set-admin-password.php'] as $file) { if (is_file($file)) { unlink($file); } }
            foreach ([$directory . '/.ordinal', $directory . '/bin', $directory] as $path) { if (is_dir($path)) { rmdir($path); } }
        }
    }
}
