<?php

declare(strict_types=1);

namespace Ordinal\Tests\Integration;

use Ordinal\Controller\BuildNumberController;
use Ordinal\Database\MigrationRunner;
use Ordinal\Http\Response;
use Ordinal\Model\AllocationCaller;
use Ordinal\Security\AllocationAuthorizer;
use Ordinal\Service\BuildNumberService;
use Ordinal\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Verifies successful controller responses against real persistent allocations. */
final class BuildNumberControllerTest extends TestCase
{
    /** Uses the guarded test database. */
    private PDO                   $connection;
    /** Names this test's isolated schema. */
    private string                $schemaName;
    /** Exercises the actual controller and transaction service. */
    private BuildNumberController $controller;
    /** Identifies the first build attempt. */
    private const string FIRST_REQUEST = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    /** Identifies a different build attempt. */
    private const string SECOND_REQUEST = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    /**
     * Creates guarded schema fixtures and injects a verified test caller.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection = TestDatabase::createConnection();
        $this->schemaName = 'controller_test_' . bin2hex(random_bytes(8));
        $this->connection->exec('CREATE SCHEMA ' . $this->schemaName);
        $this->connection->exec('SET search_path TO ' . $this->schemaName);
        (new MigrationRunner($this->connection))->applyMigrations(dirname(__DIR__, 2) . '/database/migrations');
        $this->connection->exec("INSERT INTO provider_connections (provider_kind, server_url, name) VALUES ('github', 'https://github.com', 'GitHub')");
        $this->connection->exec("INSERT INTO users (provider_connection_id, provider_user_id, display_name) VALUES (1, '1', 'First')");
        $this->connection->exec("INSERT INTO projects (provider_connection_id, provider_repository_id, name) VALUES (1, '1', 'First')");
        $authorizer = $this->createStub(AllocationAuthorizer::class);
        $authorizer->method('authorizeAllocation')->willReturn(new AllocationCaller(userId: 1));
        $this->controller = new BuildNumberController($authorizer, new BuildNumberService($this->connection));
    }

    /**
     * Removes the isolated schema and its records after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (isset($this->connection, $this->schemaName)) {
            $this->connection->exec('DROP SCHEMA IF EXISTS ' . $this->schemaName . ' CASCADE');
        }
    }

    /**
     * Returns the stable API envelope and normalizes UUID hex casing for replay.
     *
     * @return void
     */
    public function testReturnsAllocationAndDelayedReplay(): void
    {
        $first = $this->submitRequest(strtoupper(self::FIRST_REQUEST));
        self::assertSame(200, $first->statusCode);
        self::assertSame(['projectId' => 1, 'requestId' => self::FIRST_REQUEST, 'buildNumber' => 1],
            json_decode($first->body, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(2, json_decode($this->submitRequest(self::SECOND_REQUEST)->body, true, flags: JSON_THROW_ON_ERROR)['buildNumber']);
        self::assertSame($first->body, $this->submitRequest(self::FIRST_REQUEST)->body);
        self::assertSame('application/json; charset=UTF-8', $first->headers['Content-Type']);
        self::assertSame('no-store', $first->headers['Cache-Control']);
    }

    /**
     * Preserves permanent retry records after audit cleanup and simulated counter reset.
     *
     * @return void
     */
    public function testPreservesReplayAfterCleanupAndCounterReset(): void
    {
        $first = $this->submitRequest(self::FIRST_REQUEST);
        $this->connection->exec('DELETE FROM audit_events');
        $this->connection->exec('UPDATE projects SET next_build_number = 1 WHERE id = 1');
        self::assertSame(1, json_decode($this->submitRequest(self::SECOND_REQUEST)->body, true, flags: JSON_THROW_ON_ERROR)['buildNumber']);
        self::assertSame($first->body, $this->submitRequest(self::FIRST_REQUEST)->body);
        self::assertSame(2, $this->connection->query('SELECT count(*) FROM allocations')->fetchColumn());
        self::assertTrue($this->connection->query('SELECT has_allocated_build_number FROM projects WHERE id = 1')->fetchColumn());
    }

    /**
     * Returns numeric uint32 maximum, then maps exhaustion to the agreed 409 code.
     *
     * @return void
     */
    public function testMapsExhaustionAndPreservesMaximum(): void
    {
        $this->connection->exec('UPDATE projects SET next_build_number = 4294967295 WHERE id = 1');
        $first = $this->submitRequest(self::FIRST_REQUEST);
        self::assertSame(4294967295, json_decode($first->body, true, flags: JSON_THROW_ON_ERROR)['buildNumber']);
        $response = $this->submitRequest(self::SECOND_REQUEST);
        self::assertSame(409, $response->statusCode);
        self::assertSame('BUILD_COUNTER_EXHAUSTED', json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        self::assertSame($first->body, $this->submitRequest(self::FIRST_REQUEST)->body);
    }

    /**
     * Maps a verified request for a missing project to the agreed 404 error.
     *
     * @return void
     */
    public function testMapsMissingProject(): void
    {
        $response = $this->submitRequest(self::FIRST_REQUEST, '999');
        self::assertSame(404, $response->statusCode);
        self::assertSame('PROJECT_NOT_FOUND', json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['error']['code']);
    }

    /**
     * Sends validated JSON through the real controller with a non-secret test credential.
     *
     * @param string $requestId
     * @param string $projectId
     * @return Response
     */
    private function submitRequest(string $requestId, string $projectId = '1'): Response
    {
        return $this->controller->createBuildNumber($projectId, json_encode(['requestId' => $requestId], JSON_THROW_ON_ERROR), 'Bearer test-secret', 'application/json');
    }
}
