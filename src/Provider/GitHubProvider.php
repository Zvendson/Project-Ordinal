<?php

declare(strict_types=1);

namespace Ordinal\Provider;

use Ordinal\Http\CurlHttpClient;
use Ordinal\Http\HttpClient;
use Ordinal\Model\ProviderAuthorization;
use Ordinal\Model\ProviderConfiguration;
use Ordinal\Model\ProviderIdentity;
use Ordinal\Model\ProviderRepository;
use Ordinal\Model\RepositoryPermissions;
use SensitiveParameter;

/** Uses GitHub App user access tokens and verifies repository installation coverage. */
final class GitHubProvider extends Provider
{
    /** Fixes the browser login and token origin for GitHub.com. */
    private const string LOGIN_URL = 'https://github.com/login/oauth';
    /** Fixes the API origin so provider responses cannot redirect credentials elsewhere. */
    private const string API_URL = 'https://api.github.com';
    /** Pins the documented REST representation used by this integration. */
    private const string API_VERSION = '2026-03-10';
    /** Bounds all nested discovery requests together. */
    private const int MAX_DISCOVERY_REQUESTS = 100;

    /**
     * Creates a provider bound to one registered GitHub App connection.
     *
     * @param int $providerConnectionId
     * @param ProviderConfiguration $configuration
     * @param HttpClient $httpClient
     */
    public function __construct(
        int                                    $providerConnectionId,
        /** Contains the registered GitHub App client credentials. */
        #[SensitiveParameter]
        private readonly ProviderConfiguration $configuration,
        /** Sends requests through the shared bounded HTTPS transport. */
        private readonly HttpClient            $httpClient = new CurlHttpClient(),
    ) {
        parent::__construct($providerConnectionId);
    }

    /**
     * Creates a GitHub App login URL with state and PKCE, without OAuth App scopes.
     *
     * @param string $state
     * @param string $codeChallenge
     * @return string
     */
    public function createAuthorizationUrl(string $state, string $codeChallenge): string
    {
        $this->assertAuthorizationRequest($state, $codeChallenge);
        return self::LOGIN_URL . '/authorize?' . http_build_query([
            'client_id' => $this->configuration->clientId,
            'redirect_uri' => $this->configuration->redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges a code for expiring GitHub App user access and refresh tokens.
     *
     * @param string $authorizationCode
     * @param string $codeVerifier
     * @return ProviderAuthorization
     */
    public function exchangeAuthorizationCode(
        #[SensitiveParameter]
        string $authorizationCode,
        #[SensitiveParameter]
        string $codeVerifier,
    ): ProviderAuthorization {
        $this->assertAuthorizationCode($authorizationCode, $codeVerifier);
        $form = $this->createClientForm($this->configuration) + [
            'redirect_uri' => $this->configuration->redirectUri,
            'code' => $authorizationCode,
            'code_verifier' => $codeVerifier,
        ];
        return $this->createAuthorization($this->requestJson($this->httpClient, 'POST', self::LOGIN_URL . '/access_token', ['Accept' => 'application/json'], $form), true);
    }

    /**
     * Obtains rotated user access and refresh tokens without saving them yet.
     *
     * @param ProviderAuthorization $authorization
     * @return ProviderAuthorization
     */
    public function refreshAuthorization(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
    ): ProviderAuthorization {
        $form = $this->createClientForm($this->configuration) + [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->getRefreshToken($authorization),
        ];
        return $this->createAuthorization($this->requestJson($this->httpClient, 'POST', self::LOGIN_URL . '/access_token', ['Accept' => 'application/json'], $form), true);
    }

    /**
     * Looks up the token holder using the current user endpoint.
     *
     * @param ProviderAuthorization $authorization
     * @return ProviderIdentity
     */
    public function findIdentity(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
    ): ProviderIdentity {
        $data = $this->requestApi($authorization, '/user');
        $userName = $this->getRequiredString($data, 'login');
        $displayName = isset($data['name']) ? $this->getRequiredString($data, 'name') : $userName;
        return new ProviderIdentity($this->getProviderConnectionId(), $this->getImmutableId($data['id'] ?? null), $userName, $displayName);
    }

    /**
     * Resolves an immutable repository ID only inside accessible app installations.
     *
     * @param ProviderAuthorization $authorization
     * @param string $providerRepositoryId
     * @return ProviderRepository
     * @throws ProviderUnavailableException
     */
    public function findRepository(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        string                $providerRepositoryId,
    ): ProviderRepository {
        $repository = $this->findInstalledRepository($authorization, $providerRepositoryId);
        if ($repository === null) {
            throw new ProviderUnavailableException('Repository is unavailable through this provider connection.');
        }
        return $repository;
    }

    /**
     * Checks fresh repository names and effective base permission for the verified user.
     *
     * @param ProviderAuthorization $authorization
     * @param ProviderIdentity $identity
     * @param ProviderRepository $repository
     * @return RepositoryPermissions
     */
    protected function fetchRepositoryPermissions(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        ProviderIdentity      $identity,
        ProviderRepository    $repository,
    ): RepositoryPermissions {
        $currentRepository = $this->findInstalledRepository($authorization, $repository->providerRepositoryId);
        if ($currentRepository === null) {
            return new RepositoryPermissions();
        }
        $parts = explode('/', $currentRepository->name);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        $path = '/repos/' . rawurlencode($parts[0]) . '/' . rawurlencode($parts[1]) . '/collaborators/' . rawurlencode($identity->userName) . '/permission';
        $data = $this->requestApi($authorization, $path, true);
        if ($data === null) {
            return new RepositoryPermissions();
        }
        if (!is_array($data['user'] ?? null) || $this->getImmutableId($data['user']['id'] ?? null) !== $identity->providerUserId) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        $permission = $this->getRequiredString($data, 'permission');
        $currentData = $this->requestApi($authorization, '/repos/' . rawurlencode($parts[0]) . '/' . rawurlencode($parts[1]), true);
        if ($currentData === null) {
            return new RepositoryPermissions();
        }
        if ($this->getImmutableId($currentData['id'] ?? null) !== $repository->providerRepositoryId) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        return new RepositoryPermissions(in_array($permission, ['write', 'admin'], true), $permission === 'admin');
    }

    /**
     * Scans bounded installation/repository pages and refuses to treat truncation as absence.
     *
     * @param ProviderAuthorization $authorization
     * @param string $providerRepositoryId
     * @return ?ProviderRepository
     * @throws ProviderUnavailableException
     */
    private function findInstalledRepository(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        string                $providerRepositoryId,
    ): ?ProviderRepository {
        $this->assertAuthorization($authorization);
        $this->assertImmutableId($providerRepositoryId);
        $remainingRequests = self::MAX_DISCOVERY_REQUESTS;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $this->requestDiscovery($authorization, '/user/installations?per_page=' . self::PAGE_SIZE . '&page=' . $page, $remainingRequests);
            $installations = $this->getRequiredList($data, 'installations');
            foreach ($installations as $installation) {
                if (!is_array($installation)) {
                    throw new ProviderUnavailableException('Provider response could not be verified.');
                }
                $installationId = $this->getImmutableId($installation['id'] ?? null);
                $repository = $this->findInstallationRepository($authorization, $installationId, $providerRepositoryId, $remainingRequests);
                if ($repository !== null) {
                    return $repository;
                }
            }
            if (count($installations) < self::PAGE_SIZE) {
                return null;
            }
        }
        throw new ProviderUnavailableException('Provider repository discovery exceeded its limit.');
    }

    /**
     * Finds a repository ID inside one installation's paginated accessible repositories.
     *
     * @param ProviderAuthorization $authorization
     * @param string $installationId
     * @param string $providerRepositoryId
     * @param int $remainingRequests
     * @return ?ProviderRepository
     */
    private function findInstallationRepository(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        string                $installationId,
        string                $providerRepositoryId,
        int                   &$remainingRequests,
    ): ?ProviderRepository {
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $this->requestDiscovery($authorization, '/user/installations/' . $installationId . '/repositories?per_page=' . self::PAGE_SIZE . '&page=' . $page, $remainingRequests, true);
            if ($data === null) {
                return null;
            }
            $repositories = $this->getRequiredList($data, 'repositories');
            foreach ($repositories as $repository) {
                if (!is_array($repository)) {
                    throw new ProviderUnavailableException('Provider response could not be verified.');
                }
                if ($this->getImmutableId($repository['id'] ?? null) === $providerRepositoryId) {
                    return new ProviderRepository($this->getProviderConnectionId(), $providerRepositoryId, $this->getRequiredString($repository, 'full_name'));
                }
            }
            if (count($repositories) < self::PAGE_SIZE) {
                return null;
            }
        }
        throw new ProviderUnavailableException('Provider repository discovery exceeded its limit.');
    }

    /**
     * Counts nested discovery requests against one limit before sending.
     *
     * @param ProviderAuthorization $authorization
     * @param string $path
     * @param int $remainingRequests
     * @param bool $canBeMissing
     * @return ?array
     */
    private function requestDiscovery(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        string                $path,
        int                   &$remainingRequests,
        bool                  $canBeMissing = false,
    ): ?array {
        if ($remainingRequests <= 0) {
            throw new ProviderUnavailableException('Provider repository discovery exceeded its limit.');
        }
        $remainingRequests--;
        return $this->requestApi($authorization, $path, $canBeMissing);
    }

    /**
     * Sends credentials only to GitHub's fixed API origin with the pinned version.
     *
     * @param ProviderAuthorization $authorization
     * @param string $path
     * @param bool $canBeMissing
     * @return ?array
     */
    private function requestApi(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        string                $path,
        bool                  $canBeMissing = false,
    ): ?array {
        $this->assertAuthorization($authorization);
        return $this->requestJson($this->httpClient, 'GET', self::API_URL . $path, [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $authorization->accessToken,
            'X-GitHub-Api-Version' => self::API_VERSION,
        ], [], $canBeMissing);
    }
}
