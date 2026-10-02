<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Http;

use InvalidArgumentException;
use Ordinal\Http\CurlHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tests unsafe outbound requests are rejected before cURL contacts a server. */
final class CurlHttpClientTest extends TestCase
{
    /**
     * Supplies URLs, headers, and methods unsuitable for provider verification.
     *
     * @return array
     */
    public static function provideUnsafeRequests(): array
    {
        return [['GET', 'http://example.com', [], []], ['GET', 'https://user:password@example.com', [], []], ['GET', 'file:///etc/passwd', [], []], ['DELETE', 'https://example.com', [], []], ['GET', 'https://example.com', ['Authorization' => "Bearer token\r\nX-Injected: yes"], []], ['GET', 'https://example.com', [], ['secret' => 'value']]];
    }

    /**
     * Verifies invalid requests never open a connection.
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param array $form
     * @return void
     */
    #[DataProvider('provideUnsafeRequests')]
    public function testRejectsUnsafeRequest(string $method, string $url, array $headers, array $form): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CurlHttpClient())->request($method, $url, $headers, $form);
    }
}
