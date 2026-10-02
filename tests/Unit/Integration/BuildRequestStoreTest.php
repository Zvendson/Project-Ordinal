<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Integration;

use Ordinal\Integration\BuildRequestStore;
use Ordinal\Integration\BuildIntegrationException;
use PHPUnit\Framework\TestCase;

/** Verifies durable request identities scoped to server, project, and logical build attempt. */
final class BuildRequestStoreTest extends TestCase
{
    /** Holds only this test's randomly named state directory. */
    private string $directory;

    /**
     * Chooses a unique ignored directory without creating any pending IDs.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = dirname(__DIR__, 3) . '/.local/build_request_test_' . bin2hex(random_bytes(8));
    }

    /**
     * Removes only files directly in this test-created state directory.
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
     * Reuses a saved request after process replacement but isolates other projects/attempts/servers.
     *
     * @return void
     */
    public function testPersistsAndIsolatesRequestIds(): void
    {
        $store = new BuildRequestStore($this->directory);
        $id = $store->prepareRequest('https://ordinal.example', 1, 'build-42');
        self::assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id);
        self::assertSame($id, (new BuildRequestStore($this->directory))->prepareRequest('https://ordinal.example/', 1, 'build-42'));
        self::assertNotSame($id, $store->prepareRequest('https://ordinal.example', 2, 'build-42'));
        self::assertNotSame($id, $store->prepareRequest('https://ordinal.example', 1, 'build-43'));
        self::assertNotSame($id, $store->prepareRequest('https://other.example', 1, 'build-42'));
        self::assertCount(4, glob($this->directory . '/*.json'));
    }

    /**
     * Accepts an operator-supplied recovery ID and rejects replacing an already pending ID.
     *
     * @return void
     */
    public function testPreservesSuppliedRequestId(): void
    {
        $store = new BuildRequestStore($this->directory);
        $id = 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA';
        self::assertSame(strtolower($id), $store->prepareRequest('https://ordinal.example', 1, 'build-42', $id));
        self::assertSame(strtolower($id), $store->prepareRequest('https://ordinal.example', 1, 'build-42'));
        $this->expectException(BuildIntegrationException::class);
        $store->prepareRequest('https://ordinal.example', 1, 'build-42', '11111111-1111-4111-8111-111111111111');
    }

    /**
     * Refuses corrupt persisted state instead of generating another request.
     *
     * @return void
     */
    public function testStopsOnCorruptState(): void
    {
        $store = new BuildRequestStore($this->directory);
        $store->prepareRequest('https://ordinal.example', 1, 'build-42');
        $path = glob($this->directory . '/*.json')[0];
        foreach (['corrupt', ''] as $contents) {
            file_put_contents($path, $contents);
            try {
                $store->prepareRequest('https://ordinal.example', 1, 'build-42');
                self::fail('Corrupt state was replaced.');
            } catch (BuildIntegrationException) {
                self::assertSame($contents, file_get_contents($path));
            }
        }
    }

    /**
     * Rejects insecure URLs and invalid attempt/project/request input before creating state.
     *
     * @return void
     */
    public function testRejectsInvalidScope(): void
    {
        $store = new BuildRequestStore($this->directory);
        foreach ([['http://ordinal.example', 1, 'build'], ['https://user:secret@ordinal.example', 1, 'build'], ['https://ordinal.example?query=1', 1, 'build'], ['https://ordinal.example', 0, 'build'], ['https://ordinal.example', 1, '']] as [$url, $project, $attempt]) {
            try {
                $store->prepareRequest($url, $project, $attempt);
                self::fail('Invalid request scope was accepted.');
            } catch (BuildIntegrationException) {
                self::assertDirectoryDoesNotExist($this->directory);
            }
        }
    }
}
