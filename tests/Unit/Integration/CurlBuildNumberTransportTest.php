<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Integration;

use Ordinal\Integration\BuildIntegrationException;
use Ordinal\Integration\CurlBuildNumberTransport;
use PHPUnit\Framework\TestCase;

/** Verifies transport rejects insecure URLs, header injection, and invalid bounds before networking. */
final class CurlBuildNumberTransportTest extends TestCase
{
    /**
     * Rejects unsafe transport inputs without making an outbound request.
     *
     * @return void
     */
    public function testRejectsInvalidRequestInputs(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        foreach ([['http://ordinal.example/api/projects/1/build-numbers', $id, null, 30], ['https://user:secret@ordinal.example/api/projects/1/build-numbers', $id, null, 30], ['https://ordinal.example/api/projects/1/build-numbers', $id, "token\r\nInjected: yes", 30], ['https://ordinal.example/api/projects/1/build-numbers', 'invalid', null, 30], ['https://ordinal.example/api/projects/1/build-numbers', $id, null, 0], ['https://ordinal.example/api/projects/1/build-numbers', $id, null, 31]] as [$url, $requestId, $token, $timeout]) {
            try {
                (new CurlBuildNumberTransport())->requestAllocation($url, $requestId, $token, $timeout);
                self::fail('Invalid transport input was accepted.');
            } catch (BuildIntegrationException $exception) {
                self::assertStringNotContainsString('secret', $exception->getMessage());
            }
        }
    }
}
