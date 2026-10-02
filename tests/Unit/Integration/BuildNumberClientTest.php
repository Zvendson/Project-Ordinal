<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Integration;

use Ordinal\Http\HttpResponse;
use Ordinal\Integration\BuildNumberClient;
use Ordinal\Integration\BuildNumberTransport;
use Ordinal\Integration\BuildRequestStore;
use Ordinal\Integration\BuildIntegrationException;
use Ordinal\Integration\BuildTransportException;
use PHPUnit\Framework\TestCase;

/** Tests retry timing, durable identity, exact response matching, and fail-closed build behavior. */
final class BuildNumberClientTest extends TestCase
{
    /** Names the isolated ignored state directory. */
    private string $directory;
    /** Records bounded retry delays without sleeping during tests. */
    private array  $delays = [];

    /**
     * Chooses an independent ignored directory for each build-client test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = dirname(__DIR__, 3) . '/.local/build_client_test_' . bin2hex(random_bytes(8));
    }

    /**
     * Removes only this test's state files.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }
    }

    /**
     * Saves one ID before sending, reuses it through all six requests, and accepts a valid uint32 response.
     *
     * @return void
     */
    public function testRetries503WithPersistedIdentity(): void
    {
        $transport = $this->createStub(BuildNumberTransport::class);
        $ids = [];
        $transport->method('requestAllocation')->willReturnCallback(
            /**
             * Verifies persistence and request properties before simulating five temporary failures.
             *
             * @param string $url
             * @param string $requestId
             * @param ?string $token
             * @param int $timeout
             * @return HttpResponse
             */
            function (string $url, string $requestId, ?string $token, int $timeout) use (&$ids): HttpResponse {
                self::assertSame('https://ordinal.example/api/projects/2/build-numbers', $url);
                self::assertSame('fake-credential', $token);
                self::assertSame(30, $timeout);
                self::assertCount(1, glob($this->directory . '/*.json'));
                $state = json_decode(file_get_contents(glob($this->directory . '/*.json')[0]), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame($requestId, $state['requestId']);
                self::assertStringNotContainsString('fake-credential', json_encode($state, JSON_THROW_ON_ERROR));
                $ids[] = $requestId;
                return count($ids) <= 5 ? new HttpResponse(503, '{}') : new HttpResponse(200, json_encode(['projectId' => 2, 'requestId' => $requestId, 'buildNumber' => 4294967295], JSON_THROW_ON_ERROR));
            },
        );
        self::assertSame(4294967295, $this->createClient($transport)->getBuildNumber('https://ordinal.example', 2, 'build-42', 'fake-credential'));
        self::assertSame([1, 2, 4, 8, 16], $this->delays);
        self::assertCount(6, $ids);
        self::assertCount(1, array_unique($ids));
    }

    /**
     * Resumes the same pending ID after exhausted network failures and process replacement.
     *
     * @return void
     */
    public function testPreservesFailedAttemptForLaterRecovery(): void
    {
        $transport = $this->createStub(BuildNumberTransport::class);
        $transport->method('requestAllocation')->willThrowException(new BuildTransportException());
        try {
            $this->createClient($transport)->getBuildNumber('https://ordinal.example', 1, 'build-42');
            self::fail('Network failure generated a fallback build number.');
        } catch (BuildIntegrationException) {
            self::assertSame([1, 2, 4, 8, 16], $this->delays);
        }
        $state = json_decode(file_get_contents(glob($this->directory . '/*.json')[0]), true, flags: JSON_THROW_ON_ERROR);
        $replacement = $this->createStub(BuildNumberTransport::class);
        $replacement->method('requestAllocation')->willReturnCallback(
            /**
             * Checks saved identity survives a replacement client.
             *
             * @param string $url
             * @param string $id
             * @param ?string $token
             * @param int $timeout
             * @return HttpResponse
             */
            static function (string $url, string $id, ?string $token, int $timeout) use ($state): HttpResponse {
                self::assertSame($state['requestId'], $id);
                return new HttpResponse(200, json_encode(['projectId' => 1, 'requestId' => $id, 'buildNumber' => 0], JSON_THROW_ON_ERROR));
            },
        );
        self::assertSame(0, $this->createClient($replacement)->getBuildNumber('https://ordinal.example', 1, 'build-42'));
    }

    /**
     * Stops immediately on permanent HTTP errors and never repeats them automatically.
     *
     * @return void
     */
    public function testStopsOnNonRetryableStatus(): void
    {
        foreach ([400, 401, 403, 404, 409, 429, 500, 302] as $status) {
            $transport = $this->createMock(BuildNumberTransport::class);
            $transport->expects(self::once())->method('requestAllocation')->willReturn(new HttpResponse($status, 'sensitive-upstream-content'));
            try {
                $this->createClient($transport)->getBuildNumber('https://ordinal.example', 1, 'build-' . $status);
                self::fail('Permanent failure was accepted.');
            } catch (BuildIntegrationException $exception) {
                self::assertStringNotContainsString('sensitive-upstream-content', $exception->getMessage());
            }
        }
        self::assertSame([], $this->delays);
    }

    /**
     * Rejects malformed, mismatched, or non-integer success bodies without using a fallback.
     *
     * @return void
     */
    public function testValidatesSuccessfulResponse(): void
    {
        $id = '11111111-1111-4111-8111-111111111111';
        foreach (['{}', '{', json_encode(['projectId' => 2, 'requestId' => $id, 'buildNumber' => 1]), json_encode(['projectId' => 1, 'requestId' => '22222222-2222-4222-8222-222222222222', 'buildNumber' => 1]), json_encode(['projectId' => 1, 'requestId' => $id, 'buildNumber' => '1']), json_encode(['projectId' => 1, 'requestId' => $id, 'buildNumber' => -1]), json_encode(['projectId' => 1, 'requestId' => $id, 'buildNumber' => 4294967296])] as $index => $body) {
            $transport = $this->createStub(BuildNumberTransport::class);
            $transport->method('requestAllocation')->willReturn(new HttpResponse(200, $body));
            try {
                $this->createClient($transport)->getBuildNumber('https://ordinal.example', 1, 'bad-response-' . $index, requestId: $id);
                self::fail('Invalid response generated a build number.');
            } catch (BuildIntegrationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame([], $this->delays);
    }

    /**
     * Injects a recording wait function and the real persistent request store.
     *
     * @param BuildNumberTransport $transport
     * @return BuildNumberClient
     */
    private function createClient(BuildNumberTransport $transport): BuildNumberClient
    {
        return new BuildNumberClient(new BuildRequestStore($this->directory), $transport,
            /**
             * Records requested retry delays without real waiting.
             *
             * @param int $seconds
             * @return void
             */
            function (int $seconds): void { $this->delays[] = $seconds; },
        );
    }
}
