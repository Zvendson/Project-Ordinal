<?php

declare(strict_types=1);

namespace Ordinal\Service;

use Ordinal\Http\CurlHttpClient;
use Ordinal\Http\HttpClient;
use Ordinal\Model\SecurityConfiguration;
use Ordinal\Provider\GitHubProvider;
use Ordinal\Provider\GitLabProvider;
use Ordinal\Provider\Provider;
use Ordinal\Repository\ProviderConnectionRepository;

/** Resolves allowed connection records against trusted external registration secrets. */
final readonly class ProviderRegistry
{
    /**
     * Combines non-secret database records and operator-managed registrations.
     *
     * @param ProviderConnectionRepository $connections
     * @param SecurityConfiguration $configuration
     * @param HttpClient $client
     */
    public function __construct(
        /** Reads enabled connection records. */
        private ProviderConnectionRepository $connections,
        /** Supplies secrets and permitted server origins. */
        private SecurityConfiguration        $configuration,
        /** Provides injectable provider HTTP transport. */
        private HttpClient                   $client = new CurlHttpClient(),
    ) {}

    /**
     * Creates only a provider whose stable connection matches its configured registration.
     *
     * @param int $connectionId
     * @return Provider
     * @throws AccountException
     */
    public function createProvider(int $connectionId): Provider
    {
        $connection = $this->connections->findConnection($connectionId);
        $registration = $connection === null ? null : ($this->configuration->connections[$connection['registration_reference'] ?? ''] ?? null);
        if ($connection === null || $connection['disabled_at'] !== null || $registration === null
            || $connection['provider_kind'] !== $registration['kind'] || $connection['server_url'] !== $registration['serverUrl']) {
            throw new AccountException('Provider connection is unavailable.', 503);
        }
        return $registration['kind'] === 'github'
            ? new GitHubProvider($connectionId, $registration['client'], $this->client)
            : new GitLabProvider($connectionId, $registration['client'], $registration['serverUrl'], $this->client);
    }

    /**
     * Lists enabled configured connections without returning registration credentials.
     *
     * @return array
     */
    public function findAllowedConnections(): array
    {
        $allowed = [];
        foreach ($this->connections->findConnections() as $connection) {
            $registration = $this->configuration->connections[$connection['registration_reference'] ?? ''] ?? null;
            if ($registration !== null && $connection['disabled_at'] === null && $connection['provider_kind'] === $registration['kind'] && $connection['server_url'] === $registration['serverUrl']) {
                $allowed[] = $connection;
            }
        }
        return $allowed;
    }
}
