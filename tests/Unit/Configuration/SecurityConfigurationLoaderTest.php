<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Configuration;

use InvalidArgumentException;
use Ordinal\Configuration\SecurityConfigurationLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Verifies operator secrets and bootstrap registration are validated without disclosing values. */
final class SecurityConfigurationLoaderTest extends TestCase
{
    /**
     * Creates valid operator-style fake configuration.
     *
     * @return array
     */
    public static function createSettings(): array
    {
        return ['encryptionKey' => str_repeat('ab', 32), 'bootstrapConnection' => 'primary', 'bootstrapUserId' => '8', 'connections' => ['primary' => ['kind' => 'gitlab', 'serverUrl' => 'https://gitlab.example', 'clientId' => 'app', 'clientSecret' => 'private-test-secret', 'redirectUri' => 'https://ordinal.example/login/callback']]];
    }

    /**
     * Verifies identity is immutable/provider-qualified and client secrets stay in configuration only.
     *
     * @return void
     */
    public function testLoadsProviderConfiguration(): void
    {
        $configuration = SecurityConfigurationLoader::load(self::createSettings());
        self::assertSame('primary', $configuration->bootstrapConnection);
        self::assertSame('8', $configuration->bootstrapUserId);
        self::assertSame('private-test-secret', $configuration->connections['primary']['client']->clientSecret);
    }

    /**
     * Supplies missing, malformed, and unsafe operator settings.
     *
     * @return array
     */
    public static function provideInvalidSettings(): array
    {
        $invalid = [];
        foreach (['encryptionKey' => 'short', 'bootstrapConnection' => 'unknown', 'bootstrapUserId' => 'username', 'connections' => []] as $key => $value) {
            $settings = self::createSettings();
            $settings[$key] = $value;
            $invalid[] = [$settings];
        }
        foreach (['kind' => 'unknown', 'serverUrl' => 'http://gitlab.example', 'clientSecret' => '', 'redirectUri' => 'https://user:secret@ordinal.example/callback'] as $key => $value) {
            $settings = self::createSettings();
            $settings['connections']['primary'][$key] = $value;
            $invalid[] = [$settings];
        }
        $settings = self::createSettings();
        $settings['connections']['primary']['kind'] = 'github';
        $invalid[] = [$settings];
        return $invalid;
    }

    /**
     * Rejects unsafe configuration without including secrets in diagnostic messages.
     *
     * @param array $settings
     * @return void
     */
    #[DataProvider('provideInvalidSettings')]
    public function testRejectsUnsafeConfiguration(array $settings): void
    {
        try {
            SecurityConfigurationLoader::load($settings);
            self::fail('Invalid configuration was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString('private-test-secret', $exception->getMessage());
        }
    }

    /**
     * Reads only an operator-selected PHP file and suppresses accidental file output.
     *
     * @return void
     */
    public function testLoadsPrivateFileAndRejectsOutput(): void
    {
        $directory = dirname(__DIR__, 3) . '/.local/tests';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory . '/security-' . bin2hex(random_bytes(8)) . '.php';
        $previous = getenv('ORDINAL_SECURITY_CONFIG');
        try {
            file_put_contents($path, '<?php return ' . var_export(self::createSettings(), true) . ';');
            putenv('ORDINAL_SECURITY_CONFIG=' . $path);
            self::assertSame('8', SecurityConfigurationLoader::loadFromEnvironment()->bootstrapUserId);
            file_put_contents($path, '<?php echo "private-test-secret"; return [];');
            ob_start();
            try {
                SecurityConfigurationLoader::loadFromEnvironment();
                self::fail('Output-producing configuration was accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame('', ob_get_contents());
            } finally {
                ob_end_clean();
            }
        } finally {
            putenv($previous === false ? 'ORDINAL_SECURITY_CONFIG' : 'ORDINAL_SECURITY_CONFIG=' . $previous);
            unlink($path);
        }
    }
}
