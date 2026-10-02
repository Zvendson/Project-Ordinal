<?php

declare(strict_types=1);

namespace Ordinal\Provider;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ordinal\Http\CurlHttpClient;
use Ordinal\Http\HttpClient;
use Ordinal\Model\ProviderAuthorization;
use Ordinal\Model\ProviderConfiguration;
use Ordinal\Model\ProviderIdentity;
use Ordinal\Model\ProviderRepository;
use Ordinal\Model\RepositoryPermissions;
use SensitiveParameter;

/** Integrates GitLab.com and configured self-hosted instances with one implementation. */
final class GitLabProvider extends Provider
{
    /** Grants identity and repository read access without requesting write API scopes. */
    private const string SCOPES = 'read_user read_api';
    /** Defines GitLab base roles allowed to allocate numbers. */
    private const array ALLOCATION_ROLES = [30, 40, 50];
    /** Defines GitLab base roles allowed to administer a project. */
    private const array ADMINISTRATION_ROLES = [40, 50];
    /** Stores the normalized administrator-configured origin and optional installation path. */
    private readonly string $serverUrl;

    /**
     * Creates the provider for a trusted configured HTTPS instance.
     *
     * @param int $providerConnectionId
     * @param ProviderConfiguration $configuration
     * @param string $serverUrl
     * @param HttpClient $httpClient
     * @throws InvalidArgumentException
     */
    public function __construct(
        int                                    $providerConnectionId,
        /** Contains the registered client credentials and callback URL. */
        #[SensitiveParameter]
        private readonly ProviderConfiguration $configuration,
        string                                 $serverUrl,
        /** Sends requests through the shared bounded HTTPS transport. */
        private readonly HttpClient            $httpClient = new CurlHttpClient(),
    ) {
        parent::__construct($providerConnectionId);
        ProviderConfiguration::assertSecureUrl($serverUrl);
        if (parse_url($serverUrl, PHP_URL_QUERY) !== null) {
            throw new InvalidArgumentException('GitLab server URL cannot contain a query.');
        }
        $this->serverUrl = rtrim($serverUrl, '/');
    }

    /**
     * Creates the GitLab authorization-code URL with state and SHA-256 PKCE.
     *
     * @param string $state
     * @param string $codeChallenge
     * @return string
     */
    public function createAuthorizationUrl(string $state, string $codeChallenge): string
    {
        $this->assertAuthorizationRequest($state, $codeChallenge);
        return $this->serverUrl . '/oauth/authorize?' . http_build_query([
            'client_id' => $this->configuration->clientId,
            'redirect_uri' => $this->configuration->redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges a code using its original verifier and registered callback.
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
            'grant_type' => 'authorization_code',
            'code' => $authorizationCode,
            'code_verifier' => $codeVerifier,
        ];
        return $this->createAuthorization($this->requestJson($this->httpClient, 'POST', $this->serverUrl . '/oauth/token', ['Accept' => 'application/json'], $form));
    }

    /**
     * Returns both rotated credentials; coordinated persistence is implemented later.
     *
     * @param ProviderAuthorization $authorization
     * @return ProviderAuthorization
     */
    public function refreshAuthorization(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
    ): ProviderAuthorization {
        $form = $this->createClientForm($this->configuration) + [
            'redirect_uri' => $this->configuration->redirectUri,
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->getRefreshToken($authorization),
        ];
        return $this->createAuthorization($this->requestJson($this->httpClient, 'POST', $this->serverUrl . '/oauth/token', ['Accept' => 'application/json'], $form));
    }

    /**
     * Finds the token holder's immutable ID and current active identity.
     *
     * @param ProviderAuthorization $authorization
     * @return ProviderIdentity
     */
    public function findIdentity(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
    ): ProviderIdentity {
        $data = $this->requestApi($authorization, '/user');
        if ($this->getRequiredString($data, 'state') !== 'active') {
            throw new ProviderAuthenticationException('Provider authorization was rejected.');
        }
        return new ProviderIdentity($this->getProviderConnectionId(), $this->getImmutableId($data['id'] ?? null), $this->getRequiredString($data, 'username'), $this->getRequiredString($data, 'name'));
    }

    /**
     * Resolves a project using its immutable ID and verifies the returned ID matches.
     *
     * @param ProviderAuthorization $authorization
     * @param string $providerRepositoryId
     * @return ProviderRepository
     */
    public function findRepository(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        string                $providerRepositoryId,
    ): ProviderRepository {
        $this->assertImmutableId($providerRepositoryId);
        $data = $this->requestApi($authorization, '/projects/' . $providerRepositoryId);
        if ($this->getImmutableId($data['id'] ?? null) !== $providerRepositoryId) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        return new ProviderRepository($this->getProviderConnectionId(), $providerRepositoryId, $this->getRequiredString($data, 'path_with_namespace'));
    }

    /**
     * Maps effective inherited membership from explicit base roles, ignoring custom grants.
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
        $this->assertImmutableId($repository->providerRepositoryId);
        $this->assertImmutableId($identity->providerUserId);
        $data = $this->requestApi($authorization, '/projects/' . $repository->providerRepositoryId . '/members/all/' . $identity->providerUserId, true);
        if ($data === null) {
            return new RepositoryPermissions();
        }
        if ($this->getImmutableId($data['id'] ?? null) !== $identity->providerUserId
            || !is_int($data['access_level'] ?? null) || !array_key_exists('expires_at', $data)) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        if ($this->getRequiredString($data, 'state') !== 'active' || $this->hasExpiredMembership($data['expires_at'])) {
            return new RepositoryPermissions();
        }
        return new RepositoryPermissions(in_array($data['access_level'], self::ALLOCATION_ROLES, true), in_array($data['access_level'], self::ADMINISTRATION_ROLES, true));
    }

    /**
     * Sends authenticated requests only to the configured GitLab API prefix.
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
        return $this->requestJson($this->httpClient, 'GET', $this->serverUrl . '/api/v4' . $path, ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $authorization->accessToken], [], $canBeMissing);
    }

    /**
     * Rejects malformed expiration dates and conservatively denies on the expiration day.
     *
     * @param mixed $expiresAt
     * @return bool
     * @throws ProviderUnavailableException
     */
    private function hasExpiredMembership(mixed $expiresAt): bool
    {
        if ($expiresAt === null) {
            return false;
        }
        if (!is_string($expiresAt)) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $expiresAt, new DateTimeZone('UTC'));
        if ($expiry === false || $expiry->format('Y-m-d') !== $expiresAt) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        return $expiry <= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
