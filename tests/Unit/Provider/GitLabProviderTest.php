<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Provider;

use InvalidArgumentException;
use Ordinal\Http\HttpClient;
use Ordinal\Http\HttpResponse;
use Ordinal\Model\ProviderAuthorization;
use Ordinal\Model\ProviderConfiguration;
use Ordinal\Model\ProviderIdentity;
use Ordinal\Model\ProviderRepository;
use Ordinal\Provider\GitLabProvider;
use Ordinal\Provider\ProviderAuthenticationException;
use Ordinal\Provider\ProviderUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tests GitLab OAuth, provider boundaries, and effective inherited membership. */
final class GitLabProviderTest extends TestCase
{
    /**
     * Creates the configured provider using queued JSON responses.
     *
     * @param array $responses
     * @return GitLabProvider
     */
    private function createProvider(array $responses): GitLabProvider
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->exactly(count($responses)))->method('request')->willReturnOnConsecutiveCalls(...$responses);

        return new GitLabProvider(2, new ProviderConfiguration('client', 'fake-secret', 'https://ordinal.example/callback'), 'https://gitlab.example/team', $client);
    }

    /**
     * Creates a JSON fixture without contacting a provider.
     *
     * @param array $data
     * @param int $status
     * @return HttpResponse
     */
    private function createResponse(array $data, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * Creates provider-qualified fixture credentials.
     *
     * @return ProviderAuthorization
     */
    private function createAuthorization(): ProviderAuthorization
    {
        return new ProviderAuthorization(2, 'fake-access', 'fake-refresh', null);
    }

    /**
     * Verifies the same integration supports a configured self-hosted subpath with PKCE.
     *
     * @return void
     */
    public function testCreatesAuthorizationUrlWithStatePkceAndReadScopes(): void
    {
        $url = $this->createProvider([])->createAuthorizationUrl('random-state', str_repeat('a', 43));
        self::assertSame('https://gitlab.example/team/oauth/authorize', strtok($url, '?'));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('random-state', $query['state']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('read_user read_api', $query['scope']);
        self::assertSame('code', $query['response_type']);
        self::assertArrayNotHasKey('client_secret', $query);
    }

    /**
     * Checks the original verifier, redirect, and client secret reach only the token endpoint.
     *
     * @return void
     */
    public function testExchangesCodeAndUsesProviderExpiry(): void
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with('POST', 'https://gitlab.com/oauth/token', ['Accept' => 'application/json'], [
            'client_id' => 'client', 'client_secret' => 'fake-secret', 'redirect_uri' => 'https://ordinal.example/callback',
            'grant_type' => 'authorization_code', 'code' => 'fake-code', 'code_verifier' => str_repeat('a', 43),
        ])->willReturn($this->createResponse(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'token_type' => 'Bearer', 'expires_in' => 7200, 'created_at' => 1800000000]));
        $provider = new GitLabProvider(2, new ProviderConfiguration('client', 'fake-secret', 'https://ordinal.example/callback'), 'https://gitlab.com', $client);
        $authorization = $provider->exchangeAuthorizationCode('fake-code', str_repeat('a', 43));
        self::assertSame(2, $authorization->providerConnectionId);
        self::assertSame('new-access', $authorization->accessToken);
        self::assertSame('new-refresh', $authorization->refreshToken);
        self::assertSame(1800007200, $authorization->expiresAt?->getTimestamp());
        self::assertNull($authorization->refreshExpiresAt);
    }

    /**
     * Verifies rotating refresh credentials replace both old tokens.
     *
     * @return void
     */
    public function testRefreshesAuthorizationWithRotatedTokens(): void
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with('POST', 'https://gitlab.com/oauth/token', ['Accept' => 'application/json'], [
            'client_id' => 'client', 'client_secret' => 'fake-secret', 'redirect_uri' => 'https://ordinal.example/callback',
            'grant_type' => 'refresh_token', 'refresh_token' => 'fake-refresh',
        ])->willReturn($this->createResponse(['access_token' => 'rotated-access', 'refresh_token' => 'rotated-refresh', 'token_type' => 'bearer', 'expires_in' => 7200]));
        $provider = new GitLabProvider(2, new ProviderConfiguration('client', 'fake-secret', 'https://ordinal.example/callback'), 'https://gitlab.com', $client);
        $authorization = $provider->refreshAuthorization($this->createAuthorization());
        self::assertSame('rotated-access', $authorization->accessToken);
        self::assertSame('rotated-refresh', $authorization->refreshToken);
        self::assertGreaterThan(time(), $authorization->expiresAt?->getTimestamp());
    }

    /**
     * Preserves immutable IDs larger than PHP integers without floating-point loss.
     *
     * @return void
     */
    public function testFindsIdentityWithLargeImmutableId(): void
    {
        $provider = $this->createProvider([new HttpResponse(200, '{"id":9223372036854775808,"username":"alice","name":"Alice","state":"active"}')]);
        $identity = $provider->findIdentity($this->createAuthorization());
        self::assertSame('9223372036854775808', $identity->providerUserId);
        self::assertSame('alice', $identity->userName);
    }

    /**
     * Verifies project lookup uses its immutable ID rather than mutable names.
     *
     * @return void
     */
    public function testFindsRepositoryByImmutableId(): void
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with('GET', 'https://gitlab.example/team/api/v4/projects/77', ['Accept' => 'application/json', 'Authorization' => 'Bearer fake-access'], [])
            ->willReturn($this->createResponse(['id' => 77, 'path_with_namespace' => 'new-group/new-name']));
        $provider = new GitLabProvider(2, new ProviderConfiguration('client', 'fake-secret', 'https://ordinal.example/callback'), 'https://gitlab.example/team', $client);
        self::assertSame('new-group/new-name', $provider->findRepository($this->createAuthorization(), '77')->name);
    }

    /**
     * Supplies explicit base roles; unknown/custom numeric roles never imply access.
     *
     * @return array
     */
    public static function provideRoles(): array
    {
        return [[30, true, false], [40, true, true], [50, true, true], [0, false, false], [10, false, false], [20, false, false], [35, false, false], [60, false, false]];
    }

    /**
     * Checks effective inherited membership and ignores custom role names.
     *
     * @param int $role
     * @param bool $canAllocate
     * @param bool $canAdminister
     * @return void
     */
    #[DataProvider('provideRoles')]
    public function testMapsOnlyAgreedEffectiveBaseRoles(int $role, bool $canAllocate, bool $canAdminister): void
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->exactly(2))->method('request')->willReturnCallback(
            /**
             * Returns identity or effective membership for the expected immutable path.
             *
             * @param string $method
             * @param string $url
             * @param array $headers
             * @param array $form
             * @return HttpResponse
             */
            function (string $method, string $url, array $headers, array $form) use ($role): HttpResponse {
                self::assertSame('GET', $method);
                self::assertSame('Bearer fake-access', $headers['Authorization']);
                self::assertSame([], $form);
                if (str_ends_with($url, '/user')) {
                    return $this->createResponse(['id' => 8, 'username' => 'renamed', 'name' => 'Alice', 'state' => 'active']);
                }
                self::assertSame('https://gitlab.example/team/api/v4/projects/77/members/all/8', $url);
                return $this->createResponse(['id' => 8, 'state' => 'active', 'access_level' => $role, 'expires_at' => null, 'member_role_id' => 123]);
            },
        );
        $provider = new GitLabProvider(2, new ProviderConfiguration('client', 'fake-secret', 'https://ordinal.example/callback'), 'https://gitlab.example/team', $client);
        $permissions = $provider->checkRepositoryPermissions($this->createAuthorization(), new ProviderIdentity(2, '8', 'old-name', 'Alice'), new ProviderRepository(2, '77', 'old-name'));
        self::assertSame($canAllocate, $permissions->canAllocateBuildNumber);
        self::assertSame($canAdminister, $permissions->canAdministerProject);
    }

    /**
     * Denies absent or expired membership without inventing access.
     *
     * @return void
     */
    public function testDeniesMissingAndExpiredMembership(): void
    {
        foreach ([$this->createResponse([], 404), $this->createResponse(['id' => 8, 'state' => 'active', 'access_level' => 50, 'expires_at' => '2000-01-01'])] as $response) {
            $provider = $this->createProvider([$this->createResponse(['id' => 8, 'username' => 'alice', 'name' => 'Alice', 'state' => 'active']), $response]);
            self::assertFalse($provider->checkRepositoryPermissions($this->createAuthorization(), new ProviderIdentity(2, '8', 'alice', 'Alice'), new ProviderRepository(2, '77', 'name'))->canAllocateBuildNumber);
        }
    }

    /**
     * Rejects cross-instance credentials before sending any secrets.
     *
     * @return void
     */
    public function testRejectsCredentialsFromAnotherConnection(): void
    {
        $provider = $this->createProvider([]);
        $this->expectException(InvalidArgumentException::class);
        $provider->findIdentity(new ProviderAuthorization(3, 'fake-access', null, null));
    }

    /**
     * Maps rejected access credentials to an authentication failure.
     *
     * @return void
     */
    public function testRejectsRevokedAccessToken(): void
    {
        $provider = $this->createProvider([$this->createResponse(['message' => 'secret-upstream-diagnostic'], 401)]);
        $this->expectException(ProviderAuthenticationException::class);
        $this->expectExceptionMessage('Provider authorization was rejected.');
        $provider->findIdentity($this->createAuthorization());
    }

    /**
     * Supplies ambiguous upstream responses that must not produce permissions.
     *
     * @return array
     */
    public static function provideUnavailableResponses(): array
    {
        return [[429, '{}'], [503, '{}'], [302, '{}'], [403, '{}'], [200, 'not-json'], [200, '[]'], [200, '{"id":8,"username":"alice"}']];
    }

    /**
     * Stops verification on outages, redirects, rate limits, or incomplete responses.
     *
     * @param int $status
     * @param string $body
     * @return void
     */
    #[DataProvider('provideUnavailableResponses')]
    public function testFailsClosedWhenIdentityCannotBeVerified(int $status, string $body): void
    {
        $provider = $this->createProvider([new HttpResponse($status, $body)]);
        $this->expectException(ProviderUnavailableException::class);
        $provider->findIdentity($this->createAuthorization());
    }

    /**
     * Rejects OAuth errors without exposing the provider description or submitted secrets.
     *
     * @return void
     */
    public function testRejectsInvalidRefreshGrant(): void
    {
        $provider = $this->createProvider([$this->createResponse(['error' => 'invalid_grant', 'error_description' => 'fake-refresh secret'], 400)]);
        $this->expectException(ProviderAuthenticationException::class);
        $this->expectExceptionMessage('Provider authorization was rejected.');
        $provider->refreshAuthorization($this->createAuthorization());
    }

    /**
     * Treats incomplete successful token responses as unverifiable.
     *
     * @return void
     */
    public function testRejectsMissingRotatedRefreshToken(): void
    {
        $provider = $this->createProvider([$this->createResponse(['access_token' => 'new-access', 'token_type' => 'bearer', 'expires_in' => 7200])]);
        $this->expectException(ProviderUnavailableException::class);
        $provider->refreshAuthorization($this->createAuthorization());
    }

    /**
     * Rejects invalid PKCE before contacting a provider.
     *
     * @return void
     */
    public function testRejectsInvalidVerifier(): void
    {
        $provider = $this->createProvider([]);
        $this->expectException(InvalidArgumentException::class);
        $provider->exchangeAuthorizationCode('code', 'short');
    }

    /**
     * Supplies mismatched, expired, blocked, malformed, and incomplete membership data.
     *
     * @return array
     */
    public static function provideUnverifiableMemberships(): array
    {
        return [
            [['id' => 99, 'state' => 'active', 'access_level' => 50, 'expires_at' => null]],
            [['id' => 8, 'state' => 'active', 'access_level' => '50', 'expires_at' => null]],
            [['id' => 8, 'state' => 'active', 'access_level' => 50]],
            [['id' => 8, 'state' => 'active', 'access_level' => 50, 'expires_at' => '2099-02-31']],
        ];
    }

    /**
     * Rejects ambiguous membership data instead of assigning a role.
     *
     * @param array $membership
     * @return void
     */
    #[DataProvider('provideUnverifiableMemberships')]
    public function testRejectsUnverifiableMembership(array $membership): void
    {
        $provider = $this->createProvider([$this->createResponse(['id' => 8, 'username' => 'alice', 'name' => 'Alice', 'state' => 'active']), $this->createResponse($membership)]);
        $this->expectException(ProviderUnavailableException::class);
        $provider->checkRepositoryPermissions($this->createAuthorization(), new ProviderIdentity(2, '8', 'alice', 'Alice'), new ProviderRepository(2, '77', 'name'));
    }

    /**
     * Rejects blocked provider identities even when their token still returns user data.
     *
     * @return void
     */
    public function testRejectsBlockedIdentity(): void
    {
        $provider = $this->createProvider([$this->createResponse(['id' => 8, 'username' => 'alice', 'name' => 'Alice', 'state' => 'blocked'])]);
        $this->expectException(ProviderAuthenticationException::class);
        $provider->findIdentity($this->createAuthorization());
    }

    /**
     * Rejects repository IDs that changed or were misreported upstream.
     *
     * @return void
     */
    public function testRejectsMismatchedRepositoryId(): void
    {
        $provider = $this->createProvider([$this->createResponse(['id' => 99, 'path_with_namespace' => 'group/name'])]);
        $this->expectException(ProviderUnavailableException::class);
        $provider->findRepository($this->createAuthorization(), '77');
    }

    /**
     * Supplies OAuth duration values that cannot produce valid bounded expiry timestamps.
     *
     * @return array
     */
    public static function provideInvalidDurations(): array
    {
        return [[0], [-1], ['7200'], [PHP_INT_MAX]];
    }

    /**
     * Rejects invalid duration data without exposing token values in exception traces.
     *
     * @param mixed $duration
     * @return void
     */
    #[DataProvider('provideInvalidDurations')]
    public function testRejectsInvalidTokenExpiry(mixed $duration): void
    {
        $provider = $this->createProvider([$this->createResponse(['access_token' => 'hidden-access', 'refresh_token' => 'hidden-refresh', 'token_type' => 'bearer', 'expires_in' => $duration])]);
        try {
            $provider->refreshAuthorization($this->createAuthorization());
            self::fail('Invalid expiry was accepted.');
        } catch (ProviderUnavailableException $exception) {
            self::assertStringNotContainsString('hidden-access', (string) $exception);
            self::assertStringNotContainsString('hidden-refresh', (string) $exception);
        }
    }

    /**
     * Denies non-active membership even for the Owner base role.
     *
     * @return void
     */
    public function testDeniesInactiveMembership(): void
    {
        $provider = $this->createProvider([$this->createResponse(['id' => 8, 'username' => 'alice', 'name' => 'Alice', 'state' => 'active']), $this->createResponse(['id' => 8, 'state' => 'awaiting', 'access_level' => 50, 'expires_at' => null])]);
        self::assertFalse($provider->checkRepositoryPermissions($this->createAuthorization(), new ProviderIdentity(2, '8', 'alice', 'Alice'), new ProviderRepository(2, '77', 'name'))->canAllocateBuildNumber);
    }

    /**
     * Supplies origins that cannot safely identify a configured GitLab installation.
     *
     * @return array
     */
    public static function provideInvalidServerUrls(): array
    {
        return [['http://gitlab.example'], ['https://user:password@gitlab.example'], ['https://gitlab.example?other=server'], ['https://gitlab.example#fragment']];
    }

    /**
     * Rejects malformed self-hosted origins before any transport calls.
     *
     * @param string $serverUrl
     * @return void
     */
    #[DataProvider('provideInvalidServerUrls')]
    public function testRejectsInvalidServerUrl(string $serverUrl): void
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->never())->method('request');
        $this->expectException(InvalidArgumentException::class);
        new GitLabProvider(2, new ProviderConfiguration('client', 'fake-secret', 'https://ordinal.example/callback'), $serverUrl, $client);
    }

    /**
     * Refuses refresh attempts when no reusable provider credential exists.
     *
     * @return void
     */
    public function testRejectsMissingRefreshTokenWithoutARequest(): void
    {
        $provider = $this->createProvider([]);
        $this->expectException(ProviderAuthenticationException::class);
        $provider->refreshAuthorization(new ProviderAuthorization(2, 'fake-access', null, null));
    }
}
