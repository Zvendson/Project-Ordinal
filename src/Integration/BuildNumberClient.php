<?php

declare(strict_types=1);

namespace Ordinal\Integration;

use Closure;
use JsonException;
use SensitiveParameter;

/** Requests verified build numbers with persistent UUIDs and bounded retries, never fallback numbers. */
final readonly class BuildNumberClient
{
    /** Allows five retries after the initial attempt. */
    private const array RETRY_DELAYS_SECONDS = [1, 2, 4, 8, 16];
    /** Bounds each request, including its connection setup. */
    private const int REQUEST_TIMEOUT_SECONDS = 30;
    /** Accepts the full agreed uint32 range. */
    private const int MAX_BUILD_NUMBER = 4294967295;
    /** Sleeps in production and records delays in unit tests. */
    private Closure $wait;

    /**
     * Composes durable request identity, injectable transport, and retry waiting.
     *
     * @param BuildRequestStore $store
     * @param BuildNumberTransport $transport
     * @param ?Closure $wait
     */
    public function __construct(
        /** Saves the request before any remote side effect. */
        private BuildRequestStore    $store,
        /** Performs one bounded HTTPS JSON request at a time. */
        private BuildNumberTransport $transport,
        ?Closure                     $wait = null,
    ) {
        $this->wait = $wait ??
            /**
             * Applies only the agreed bounded retry delay.
             *
             * @param int $seconds
             * @return void
             */
            static function (int $seconds): void { sleep($seconds); };
    }

    /**
     * Resumes one attempt's original request after temporary failures and validates the exact success response.
     *
     * @param string $serverUrl
     * @param int $projectId
     * @param string $buildAttempt
     * @param ?string $token
     * @param ?string $requestId
     * @return int
     */
    public function getBuildNumber(
        string  $serverUrl,
        int     $projectId,
        string  $buildAttempt,
        #[SensitiveParameter]
        ?string $token     = null,
        ?string $requestId = null,
    ): int {
        $serverUrl = BuildRequestStore::normalizeServerUrl($serverUrl);
        $id = $this->store->prepareRequest($serverUrl, $projectId, $buildAttempt, $requestId);
        $url = $serverUrl . '/api/projects/' . $projectId . '/build-numbers';
        for ($attempt = 0; $attempt <= count(self::RETRY_DELAYS_SECONDS); $attempt++) {
            $response = null;
            try {
                $response = $this->transport->requestAllocation($url, $id, $token, self::REQUEST_TIMEOUT_SECONDS);
            } catch (BuildTransportException) {
                // Keep the already saved identity for the next bounded attempt.
            }
            if ($response !== null && $response->statusCode !== 503) {
                if ($response->statusCode !== 200) {
                    throw new BuildIntegrationException('Build-number allocation failed (HTTP ' . $response->statusCode . '). The pending request ID was preserved.');
                }
                try {
                    $result = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new BuildIntegrationException('The allocation response is invalid. The pending request ID was preserved.');
                }
                if (!is_array($result) || ($result['projectId'] ?? null) !== $projectId || ($result['requestId'] ?? null) !== $id
                    || !is_int($result['buildNumber'] ?? null) || $result['buildNumber'] < 0 || $result['buildNumber'] > self::MAX_BUILD_NUMBER) {
                    throw new BuildIntegrationException('The allocation response does not match this request. The pending request ID was preserved.');
                }
                return $result['buildNumber'];
            }
            if ($attempt < count(self::RETRY_DELAYS_SECONDS)) {
                ($this->wait)(self::RETRY_DELAYS_SECONDS[$attempt]);
            }
        }
        throw new BuildIntegrationException('Build-number allocation remained unavailable after six attempts. Stop the build and retain its request state for recovery.');
    }
}
