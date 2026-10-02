<?php

declare(strict_types=1);

namespace Ordinal\Tests\Support;

use Ordinal\Configuration\SecurityConfigurationLoader;
use Ordinal\Database\MigrationRunner;
use Ordinal\Http\HttpResponse;
use Ordinal\Model\BrowserSession;
use Ordinal\Service\AccountApplication;
use Ordinal\Tests\Unit\Configuration\SecurityConfigurationLoaderTest;
use PDO;

/** Shares isolated account, provider, and database setup across administrative acceptance tests. */
final class AccountFixture
{
    /** Holds the guarded test connection. */
    public ?PDO                        $connection;
    /** Composes real services against a fake provider. */
    public ?AccountApplication         $application;
    /** Names only this fixture's random schema. */
    public readonly string             $schemaName;
    /** Controls current repository role. */
    public int                         $role = 40;
    /** Overrides roles for specific immutable repository IDs in access-matrix tests. */
    public array                       $repositoryRoles = [];
    /** Simulates upstream unavailability. */
    public bool                        $isProviderUnavailable = false;

    /** Creates a fresh migrated schema and deterministic transport. */
    public function __construct()
    {
        $this->connection = TestDatabase::createConnection();
        $this->schemaName = 'phase7_test_' . bin2hex(random_bytes(8));
        $this->connection->exec('CREATE SCHEMA ' . $this->schemaName);
        $this->connection->exec('SET search_path TO ' . $this->schemaName);
        (new MigrationRunner($this->connection))->applyMigrations(dirname(__DIR__, 2) . '/database/migrations');
        $this->application = new AccountApplication($this->connection, SecurityConfigurationLoader::load(SecurityConfigurationLoaderTest::createSettings()), new AccountHttpClient($this));
    }

    /**
     * Removes only this schema and releases the application/connection resources after unfinished work.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->connection === null) { return; }
        try {
            if ($this->connection->inTransaction()) { $this->connection->rollBack(); }
            $this->connection->exec('DROP SCHEMA ' . $this->schemaName . ' CASCADE');
        } finally {
            $this->application = null;
            $this->connection = null;
        }
    }

    /**
     * Completes the actual login flow against fake provider responses.
     *
     * @param string $identity
     * @return string
     */
    public function signIn(string $identity = '8'): string
    {
        $request = $this->application->login->beginLogin(1);
        parse_str((string) parse_url($request['authorizationUrl'], PHP_URL_QUERY), $query);
        return $this->application->login->completeLogin($query['state'], $identity, $request['browserSecret']);
    }

    /**
     * Returns a current authenticated browser identity.
     *
     * @param string $identity
     * @return BrowserSession
     */
    public function createSession(string $identity = '8'): BrowserSession
    {
        return $this->application->sessions->authenticate($this->signIn($identity));
    }

    /**
     * Models identity, refresh, repository lookup and inherited roles.
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param array $form
     * @return HttpResponse
     */
    public function respond(string $method, string $url, array $headers, array $form): HttpResponse
    {
        if ($this->isProviderUnavailable) { return new HttpResponse(503, '{}'); }
        if ($method === 'POST') {
            $id = ($form['grant_type'] ?? '') === 'refresh_token' ? substr($form['refresh_token'], 8) : $form['code'];
            $data = ['access_token' => 'access-' . $id, 'refresh_token' => 'refresh-' . $id, 'token_type' => 'bearer', 'expires_in' => 3600];
        } elseif (str_ends_with($url, '/user')) {
            $id = substr($headers['Authorization'], strlen('Bearer access-'));
            $data = ['id' => (int) $id, 'username' => 'user-' . $id, 'name' => 'User ' . $id, 'state' => 'active'];
        } elseif (str_contains($url, '/members/all/')) {
            preg_match('~/projects/([0-9]+)/members/all/~', $url, $matches);
            $role = $this->repositoryRoles[$matches[1] ?? ''] ?? $this->role;
            $data = ['id' => (int) substr($url, strrpos($url, '/') + 1), 'access_level' => $role, 'state' => 'active', 'expires_at' => null];
        } else {
            $id = (int) substr($url, strrpos($url, '/') + 1);
            $data = ['id' => $id, 'path_with_namespace' => 'team/repository-' . $id];
        }
        return new HttpResponse(200, json_encode($data, JSON_THROW_ON_ERROR));
    }
}
