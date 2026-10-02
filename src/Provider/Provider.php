<?php

declare(strict_types=1);

namespace Ordinal\Provider;

use InvalidArgumentException;
use DateTimeImmutable;
use JsonException;
use Ordinal\Http\HttpClient;
use Ordinal\Model\ProviderAuthorization;
use Ordinal\Model\ProviderConfiguration;
use Ordinal\Model\ProviderIdentity;
use Ordinal\Model\ProviderRepository;
use Ordinal\Model\RepositoryPermissions;
use SensitiveParameter;

/**
 * Defines shared provider operations and verifies connection and caller identity boundaries.
 */
abstract class Provider
{
    /** Limits pagination rather than accepting unverifiable truncated discovery results. */
    protected const int MAX_PAGES = 100;
    /** Uses each provider's documented maximum page size. */
    protected const int PAGE_SIZE = 100;

    /**
     * Binds the provider to a positive configured connection ID.
     *
     * @param int $providerConnectionId
     * @throws \InvalidArgumentException
     */
    public function __construct(
        /** Identifies the configured provider connection. */
        private readonly int $providerConnectionId,
    ) {
        if ($providerConnectionId <= 0) {
            throw new InvalidArgumentException('Provider connection ID must be positive.');
        }
    }

    /**
     * Returns the configured connection identifier.
     *
     * @return int
     */
    final public function getProviderConnectionId(): int
    {
        return $this->providerConnectionId;
    }

    /**
     * Builds the provider login URL using caller-supplied state and PKCE challenge.
     *
     * @param string $state
     * @param string $codeChallenge
     * @return string
     */
    abstract public function createAuthorizationUrl(string $state, string $codeChallenge): string;

    /**
     * Exchanges a login code and its original PKCE verifier for provider credentials.
     *
     * @param string $authorizationCode
     * @param string $codeVerifier
     * @return ProviderAuthorization
     */
    abstract public function exchangeAuthorizationCode(
        #[SensitiveParameter]
        string $authorizationCode,
        #[SensitiveParameter]
        string $codeVerifier,
    ): ProviderAuthorization;

    /**
     * Obtains replacement provider credentials using the existing authorization.
     *
     * @param ProviderAuthorization $authorization
     * @return ProviderAuthorization
     */
    abstract public function refreshAuthorization(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
    ): ProviderAuthorization;

    /**
     * Looks up the immutable identity belonging to the provider authorization.
     *
     * @param ProviderAuthorization $authorization
     * @return ProviderIdentity
     */
    abstract public function findIdentity(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
    ): ProviderIdentity;

    /**
     * Looks up repository metadata using its immutable provider repository ID.
     *
     * @param ProviderAuthorization $authorization
     * @param string $providerRepositoryId
     * @return ProviderRepository
     */
    abstract public function findRepository(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        string                $providerRepositoryId,
    ): ProviderRepository;

    /**
     * Verifies connection and token-holder identity before checking repository access.
     *
     * @param ProviderAuthorization $authorization
     * @param ProviderIdentity $identity
     * @param ProviderRepository $repository
     * @return RepositoryPermissions
     * @throws \InvalidArgumentException
     */
    final public function checkRepositoryPermissions(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        ProviderIdentity      $identity,
        ProviderRepository    $repository,
    ): RepositoryPermissions {
        $recordConnectionIds = [
            $authorization->providerConnectionId,
            $identity->providerConnectionId,
            $repository->providerConnectionId,
        ];

        foreach ($recordConnectionIds as $recordConnectionId) {
            if ($recordConnectionId !== $this->providerConnectionId) {
                throw new InvalidArgumentException('Provider records must belong to the same connection.');
            }
        }

        $verifiedIdentity = $this->findIdentity($authorization);

        if ($verifiedIdentity->providerConnectionId !== $this->providerConnectionId
            || $verifiedIdentity->providerUserId !== $identity->providerUserId) {
            throw new InvalidArgumentException('Provider authorization must belong to the requested identity.');
        }

        return $this->fetchRepositoryPermissions($authorization, $verifiedIdentity, $repository);
    }

    /**
     * Obtains current effective repository permissions from the concrete provider.
     *
     * @param ProviderAuthorization $authorization
     * @param ProviderIdentity $identity
     * @param ProviderRepository $repository
     * @return RepositoryPermissions
     */
    abstract protected function fetchRepositoryPermissions(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
        ProviderIdentity      $identity,
        ProviderRepository    $repository,
    ): RepositoryPermissions;

    /**
     * Validates state and a SHA-256 PKCE challenge before building a login URL.
     *
     * @param string $state
     * @param string $codeChallenge
     * @return void
     * @throws InvalidArgumentException
     */
    final protected function assertAuthorizationRequest(string $state, string $codeChallenge): void
    {
        if (trim($state) === '' || preg_match('/^[A-Za-z0-9_-]{43}$/D', $codeChallenge) !== 1) {
            throw new InvalidArgumentException('Authorization requires state and a SHA-256 PKCE challenge.');
        }
    }

    /**
     * Validates the original authorization code and PKCE verifier before an exchange.
     *
     * @param string $authorizationCode
     * @param string $codeVerifier
     * @return void
     * @throws InvalidArgumentException
     */
    final protected function assertAuthorizationCode(
        #[SensitiveParameter]
        string $authorizationCode,
        #[SensitiveParameter]
        string $codeVerifier,
    ): void {
        if (trim($authorizationCode) === '' || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $codeVerifier) !== 1) {
            throw new InvalidArgumentException('Authorization code and PKCE verifier must be valid.');
        }
    }

    /**
     * Checks credentials belong to this connection before sending them anywhere.
     *
     * @param ProviderAuthorization $authorization
     * @return void
     * @throws InvalidArgumentException
     * @throws ProviderAuthenticationException
     */
    final protected function assertAuthorization(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
    ): void {
        if ($authorization->providerConnectionId !== $this->getProviderConnectionId()) {
            throw new InvalidArgumentException('Provider credentials must belong to this connection.');
        }
        if (trim($authorization->accessToken) === '') {
            throw new ProviderAuthenticationException('Provider authorization was rejected.');
        }
    }

    /**
     * Validates credentials and obtains the reusable refresh token.
     *
     * @param ProviderAuthorization $authorization
     * @return string
     * @throws ProviderAuthenticationException
     */
    final protected function getRefreshToken(
        #[SensitiveParameter]
        ProviderAuthorization $authorization,
    ): string {
        $this->assertAuthorization($authorization);
        if ($authorization->refreshToken === null || trim($authorization->refreshToken) === '') {
            throw new ProviderAuthenticationException('Provider authorization was rejected.');
        }
        return $authorization->refreshToken;
    }

    /**
     * Builds registered client form fields used by both token exchanges.
     *
     * @param ProviderConfiguration $configuration
     * @return array
     */
    final protected function createClientForm(#[SensitiveParameter] ProviderConfiguration $configuration): array
    {
        return ['client_id' => $configuration->clientId, 'client_secret' => $configuration->clientSecret];
    }

    /**
     * Interprets JSON and status without exposing raw responses; optional 404 means confirmed absence.
     *
     * @param HttpClient $client
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param array $form
     * @param bool $canBeMissing
     * @return ?array
     * @throws ProviderAuthenticationException
     * @throws ProviderUnavailableException
     */
    final protected function requestJson(
        HttpClient $client,
        string     $method,
        string     $url,
        #[SensitiveParameter]
        array      $headers      = [],
        #[SensitiveParameter]
        array      $form         = [],
        bool       $canBeMissing = false,
    ): ?array {
        $response = $client->request($method, $url, $headers, $form);
        if ($response->statusCode === 401) {
            throw new ProviderAuthenticationException('Provider authorization was rejected.');
        }
        if ($response->statusCode === 404 && $canBeMissing) {
            return null;
        }
        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        if (!is_array($data) || !str_starts_with(ltrim($response->body), '{')) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        if (in_array($data['error'] ?? null, ['invalid_grant', 'bad_verification_code', 'expired_token', 'incorrect_client_credentials', 'invalid_client'], true)
            && in_array($response->statusCode, [200, 400, 401], true)) {
            throw new ProviderAuthenticationException('Provider authorization was rejected.');
        }
        if ($response->statusCode !== 200 || isset($data['error'])) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        return $data;
    }

    /**
     * Requires a nonempty response string, retaining whitespace only where meaningful.
     *
     * @param array $data
     * @param string $key
     * @return string
     * @throws ProviderUnavailableException
     */
    final protected function getRequiredString(#[SensitiveParameter] array $data, string $key): string
    {
        if (!is_string($data[$key] ?? null) || trim($data[$key]) === '') {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        return $data[$key];
    }

    /**
     * Validates a canonical positive immutable provider ID without numeric casts.
     *
     * @param mixed $value
     * @return string
     * @throws ProviderUnavailableException
     */
    final protected function getImmutableId(mixed $value): string
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9][0-9]*$/D', (string) $value) !== 1) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        return (string) $value;
    }

    /**
     * Validates an immutable ID supplied by application code.
     *
     * @param string $providerId
     * @return void
     * @throws InvalidArgumentException
     */
    final protected function assertImmutableId(string $providerId): void
    {
        if (preg_match('/^[1-9][0-9]*$/D', $providerId) !== 1) {
            throw new InvalidArgumentException('Provider ID must be a canonical positive integer.');
        }
    }

    /**
     * Parses expiring rotating OAuth credentials, preserving provider issue timestamps.
     *
     * @param array $data
     * @param bool $hasRefreshExpiry
     * @return ProviderAuthorization
     * @throws ProviderUnavailableException
     */
    final protected function createAuthorization(
        #[SensitiveParameter]
        array $data,
        bool  $hasRefreshExpiry = false,
    ): ProviderAuthorization {
        if (strtolower($this->getRequiredString($data, 'token_type')) !== 'bearer') {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        $issuedAt = $data['created_at'] ?? time();
        if (!is_int($issuedAt) || $issuedAt <= 0) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        return new ProviderAuthorization(
            $this->getProviderConnectionId(),
            $this->getRequiredString($data, 'access_token'),
            $this->getRequiredString($data, 'refresh_token'),
            $this->createExpiry($issuedAt, $data['expires_in'] ?? null),
            $hasRefreshExpiry ? $this->createExpiry($issuedAt, $data['refresh_token_expires_in'] ?? null) : null,
        );
    }

    /**
     * Creates a UTC expiry while rejecting invalid durations and arithmetic overflow.
     *
     * @param int $issuedAt
     * @param mixed $duration
     * @return DateTimeImmutable
     * @throws ProviderUnavailableException
     */
    private function createExpiry(int $issuedAt, mixed $duration): DateTimeImmutable
    {
        if (!is_int($duration) || $duration <= 0 || $duration > PHP_INT_MAX - $issuedAt) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        return new DateTimeImmutable('@' . ($issuedAt + $duration));
    }

    /**
     * Requires a provider list for bounded pagination.
     *
     * @param array $data
     * @param string $key
     * @return array
     * @throws ProviderUnavailableException
     */
    final protected function getRequiredList(array $data, string $key): array
    {
        if (!is_array($data[$key] ?? null) || !array_is_list($data[$key])) {
            throw new ProviderUnavailableException('Provider response could not be verified.');
        }
        return $data[$key];
    }
}
