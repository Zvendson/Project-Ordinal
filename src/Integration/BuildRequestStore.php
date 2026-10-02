<?php

declare(strict_types=1);

namespace Ordinal\Integration;

use InvalidArgumentException;
use JsonException;
use Ordinal\Model\ProviderConfiguration;
use Ordinal\Model\RequestId;

/** Durably saves one request identity before any network operation, scoped to instance/project/build attempt. */
final readonly class BuildRequestStore
{
    /** Bounds readable logical attempt identifiers. */
    private const int MAX_ATTEMPT_BYTES = 200;
    /** Bounds the configured base URL and saved JSON state. */
    private const int MAX_URL_BYTES = 2048;
    /** Keeps corrupted/unexpected state reads bounded. */
    private const int MAX_STATE_BYTES = 8192;

    /**
     * Selects an operator-managed state directory that must survive retries.
     *
     * @param string $directory
     */
    public function __construct(
        /** Stores only request metadata; never tokens, provider secrets, or passwords. */
        private string $directory,
    ) {}

    /**
     * Canonicalizes a secure instance URL without query, credentials, or fragment.
     *
     * @param string $serverUrl
     * @return string
     */
    public static function normalizeServerUrl(string $serverUrl): string
    {
        try {
            ProviderConfiguration::assertSecureUrl($serverUrl);
        } catch (InvalidArgumentException) {
            throw new BuildIntegrationException('Use an HTTPS instance URL without embedded credentials, query, or fragment.');
        }
        if (parse_url($serverUrl, PHP_URL_QUERY) !== null || strlen($serverUrl) > self::MAX_URL_BYTES) {
            throw new BuildIntegrationException('Use an HTTPS instance URL without a query and within the supported URL length.');
        }
        return rtrim($serverUrl, '/');
    }

    /**
     * Returns the already saved ID or durably creates a new one under a file lock.
     *
     * @param string $serverUrl
     * @param int $projectId
     * @param string $buildAttempt
     * @param ?string $requestId
     * @return string
     */
    public function prepareRequest(string $serverUrl, int $projectId, string $buildAttempt, ?string $requestId = null): string
    {
        $serverUrl = self::normalizeServerUrl($serverUrl);
        if ($projectId < 1 || trim($buildAttempt) === '' || strlen($buildAttempt) > self::MAX_ATTEMPT_BYTES
            || preg_match('/[\x00-\x1f\x7f]/', $buildAttempt) === 1 || ($requestId !== null && !RequestId::isValid($requestId))) {
            throw new BuildIntegrationException('Choose a positive project ID, a logical build attempt, and an optional UUID-v4 recovery ID.');
        }
        $scope = ['serverUrl' => $serverUrl, 'projectId' => $projectId, 'buildAttempt' => $buildAttempt];
        try {
            $scopeJson = json_encode($scope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new BuildIntegrationException('Build request metadata must be valid UTF-8.');
        }
        if ($this->directory === '' || (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory))) {
            throw new BuildIntegrationException('The request state directory is unavailable.');
        }
        $path = $this->directory . '/' . hash('sha256', $scopeJson) . '.json';
        if (is_link($path)) {
            throw new BuildIntegrationException('Request state cannot be a symbolic link.');
        }
        $handle = @fopen($path, 'x+b');
        $isNewState = $handle !== false;
        if (!$isNewState) {
            $handle = @fopen($path, 'r+b');
        }
        if ($handle === false) {
            throw new BuildIntegrationException('Request state could not be opened.');
        }
        try {
            if (!@chmod($path, 0600) || !flock($handle, LOCK_EX)) {
                throw new BuildIntegrationException('Request state could not be protected or locked.');
            }
            $saved = stream_get_contents($handle, self::MAX_STATE_BYTES + 1);
            if ($saved === false || strlen($saved) > self::MAX_STATE_BYTES) {
                throw new BuildIntegrationException('Saved request state is unreadable or invalid. Restore its original request ID before retrying.');
            }
            if ($saved !== '') {
                try {
                    $state = json_decode($saved, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new BuildIntegrationException('Saved request state is invalid. Restore its original request ID before retrying.');
                }
                if (!is_array($state) || ($state['serverUrl'] ?? null) !== $serverUrl || ($state['projectId'] ?? null) !== $projectId
                    || ($state['buildAttempt'] ?? null) !== $buildAttempt || !is_string($state['requestId'] ?? null) || !RequestId::isValid($state['requestId'])
                    || ($requestId !== null && strtolower($requestId) !== $state['requestId'])) {
                    throw new BuildIntegrationException('Saved request identity does not match this build. Keep its original ID for recovery.');
                }
                return $state['requestId'];
            }
            if (!$isNewState) {
                throw new BuildIntegrationException('Existing request state is empty. Restore its original request ID before retrying.');
            }
            $id = $requestId === null ? RequestId::create() : strtolower($requestId);
            $contents = json_encode($scope + ['requestId' => $id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            $written = 0;
            while ($written < strlen($contents)) {
                $count = fwrite($handle, substr($contents, $written));
                if ($count === false || $count === 0) {
                    throw new BuildIntegrationException('Request identity could not be saved; no allocation was sent.');
                }
                $written += $count;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new BuildIntegrationException('Request identity could not be made durable; no allocation was sent.');
            }
            return $id;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
