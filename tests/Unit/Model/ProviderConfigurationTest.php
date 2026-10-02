<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Model;

use InvalidArgumentException;
use Ordinal\Model\ProviderConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tests provider client configuration validation before secrets can be sent. */
final class ProviderConfigurationTest extends TestCase
{
    /**
     * Supplies invalid client credentials and callback URLs.
     *
     * @return array
     */
    public static function provideInvalidConfigurations(): array
    {
        return [['', 'secret', 'https://ordinal.example/callback'], ['client', '', 'https://ordinal.example/callback'], ['client', 'secret', 'http://ordinal.example/callback'], ['client', 'secret', 'https://user:password@ordinal.example/callback'], ['client', 'secret', 'https://ordinal.example/callback#fragment']];
    }

    /**
     * Rejects incomplete credentials and unsafe callbacks.
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param string $redirectUri
     * @return void
     */
    #[DataProvider('provideInvalidConfigurations')]
    public function testRejectsInvalidConfiguration(string $clientId, string $clientSecret, string $redirectUri): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProviderConfiguration($clientId, $clientSecret, $redirectUri);
    }
}
