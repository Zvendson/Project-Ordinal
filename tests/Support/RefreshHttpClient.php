<?php

declare(strict_types=1);

namespace Ordinal\Tests\Support;

use Ordinal\Http\HttpClient;
use Ordinal\Http\HttpResponse;
use PDO;
use SensitiveParameter;

/** Records independent refresh-worker calls without contacting a real provider. */
final class RefreshHttpClient extends HttpClient
{
    /**
     * Uses the guarded worker connection to record simulated refresh requests.
     *
     * @param PDO $connection
     */
    public function __construct(
        /** Stores only fixture call counts in a test-created table. */
        private readonly PDO $connection,
    ) {}

    /**
     * Returns a rotated fixture pair or its verified immutable user identity.
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param array $form
     * @return HttpResponse
     */
    public function request(
        string $method,
        string $url,
        #[SensitiveParameter]
        array  $headers = [],
        #[SensitiveParameter]
        array  $form    = [],
    ): HttpResponse {
        if ($method === 'POST') {
            if (($form['refresh_token'] ?? '') !== 'refresh-8') {
                return new HttpResponse(400, '{"error":"invalid_grant"}');
            }
            $this->connection->exec('INSERT INTO refresh_calls DEFAULT VALUES');
            $data = ['access_token' => 'rotated-access-8', 'refresh_token' => 'rotated-refresh-8', 'token_type' => 'bearer', 'expires_in' => 3600];
        } else {
            $data = ['id' => 8, 'username' => 'alice', 'name' => 'Alice', 'state' => 'active'];
        }
        return new HttpResponse(200, json_encode($data, JSON_THROW_ON_ERROR));
    }
}
