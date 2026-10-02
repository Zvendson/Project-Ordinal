<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Provider;

use Ordinal\Http\HttpClient;
use Ordinal\Http\HttpResponse;
use Ordinal\Model\ProviderAuthorization;
use Ordinal\Model\ProviderConfiguration;
use Ordinal\Model\ProviderIdentity;
use Ordinal\Model\ProviderRepository;
use Ordinal\Provider\GitHubProvider;
use Ordinal\Provider\ProviderUnavailableException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tests GitHub App user authorization and installed repository permission checks. */
final class GitHubProviderTest extends TestCase
{
    /**
     * Creates the GitHub provider with an injected mocked transport.
     *
     * @param array $responses
     * @return GitHubProvider
     */
    private function createProvider(array $responses): GitHubProvider
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->exactly(count($responses)))->method('request')->willReturnOnConsecutiveCalls(...$responses);
        return new GitHubProvider(1, new ProviderConfiguration('client', 'fake-secret', 'https://ordinal.example/callback'), $client);
    }

    /**
     * Encodes a provider fixture as JSON.
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
     * Creates fixture credentials belonging to this connection.
     *
     * @return ProviderAuthorization
     */
    private function createAuthorization(): ProviderAuthorization
    {
        return new ProviderAuthorization(1, 'fake-access', 'fake-refresh', null);
    }

    /**
     * Uses state and PKCE without OAuth App scopes for a GitHub App login.
     *
     * @return void
     */
    public function testCreatesGitHubAppAuthorizationUrl(): void
    {
        $url = $this->createProvider([])->createAuthorizationUrl('state', str_repeat('a', 43));
        self::assertSame('https://github.com/login/oauth/authorize', strtok($url, '?'));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('state', $query['state']);
        self::assertArrayNotHasKey('scope', $query);
        self::assertArrayNotHasKey('client_secret', $query);
    }

    /**
     * Exchanges PKCE credentials and keeps separate access/refresh expirations.
     *
     * @return void
     */
    public function testExchangesCodeWithPkceAndExpiringTokens(): void
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with('POST', 'https://github.com/login/oauth/access_token', ['Accept' => 'application/json'], [
            'client_id' => 'client', 'client_secret' => 'fake-secret', 'redirect_uri' => 'https://ordinal.example/callback',
            'code' => 'fake-code', 'code_verifier' => str_repeat('a', 43),
        ])->willReturn($this->createResponse(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'token_type' => 'bearer', 'expires_in' => 28800, 'refresh_token_expires_in' => 15897600]));
        $provider = new GitHubProvider(1, new ProviderConfiguration('client', 'fake-secret', 'https://ordinal.example/callback'), $client);
        $authorization = $provider->exchangeAuthorizationCode('fake-code', str_repeat('a', 43));
        self::assertSame('new-access', $authorization->accessToken);
        self::assertSame('new-refresh', $authorization->refreshToken);
        self::assertSame(15897600 - 28800, $authorization->refreshExpiresAt?->getTimestamp() - $authorization->expiresAt?->getTimestamp());
    }

    /**
     * Supplies documented permission values and unknown role values.
     *
     * @return array
     */
    public static function providePermissions(): array
    {
        return [['write', true, false], ['admin', true, true], ['read', false, false], ['none', false, false], ['custom', false, false]];
    }

    /**
     * Checks fresh usernames, immutable repository lookup, app coverage, and base permissions.
     *
     * @param string $permission
     * @param bool $canAllocate
     * @param bool $canAdminister
     * @return void
     */
    #[DataProvider('providePermissions')]
    public function testChecksInstalledRepositoryAndVerifiedCurrentUser(string $permission, bool $canAllocate, bool $canAdminister): void
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->exactly(5))->method('request')->willReturnCallback(
            /**
             * Returns fixtures only for the documented fixed-origin API routes.
             *
             * @param string $method
             * @param string $url
             * @param array $headers
             * @param array $form
             * @return HttpResponse
             */
            function (string $method, string $url, array $headers, array $form) use ($permission): HttpResponse {
                self::assertSame('GET', $method);
                self::assertSame('Bearer fake-access', $headers['Authorization']);
                self::assertSame('2026-03-10', $headers['X-GitHub-Api-Version']);
                self::assertSame([], $form);
                return match ($url) {
                    'https://api.github.com/user' => $this->createResponse(['id' => 8, 'login' => 'renamed', 'name' => null]),
                    'https://api.github.com/user/installations?per_page=100&page=1' => $this->createResponse(['installations' => [['id' => 4]]]),
                    'https://api.github.com/user/installations/4/repositories?per_page=100&page=1' => $this->createResponse(['repositories' => [['id' => 77, 'full_name' => 'owner/renamed-repo']]]),
                    'https://api.github.com/repos/owner/renamed-repo/collaborators/renamed/permission' => $this->createResponse(['permission' => $permission, 'role_name' => 'custom-admin', 'user' => ['id' => 8]]),
                    'https://api.github.com/repos/owner/renamed-repo' => $this->createResponse(['id' => 77]),
                    default => self::fail('Unexpected provider request.'),
                };
            },
        );
        $provider = new GitHubProvider(1, new ProviderConfiguration('client', 'fake-secret', 'https://ordinal.example/callback'), $client);
        $permissions = $provider->checkRepositoryPermissions($this->createAuthorization(), new ProviderIdentity(1, '8', 'old-user', 'Alice'), new ProviderRepository(1, '77', 'old-owner/old-repo'));
        self::assertSame($canAllocate, $permissions->canAllocateBuildNumber);
        self::assertSame($canAdminister, $permissions->canAdministerProject);
    }

    /**
     * Denies repositories absent from the user's GitHub App installations.
     *
     * @return void
     */
    public function testDeniesRepositoryOutsideAppInstallation(): void
    {
        $provider = $this->createProvider([$this->createResponse(['id' => 8, 'login' => 'alice']), $this->createResponse(['installations' => [['id' => 4]]]), $this->createResponse(['repositories' => [['id' => 99, 'full_name' => 'owner/other']]])]);
        self::assertFalse($provider->checkRepositoryPermissions($this->createAuthorization(), new ProviderIdentity(1, '8', 'alice', 'Alice'), new ProviderRepository(1, '77', 'owner/repo'))->canAllocateBuildNumber);
    }

    /**
     * Rejects a permission response for another immutable user rather than trusting the username.
     *
     * @return void
     */
    public function testRejectsMismatchedPermissionIdentity(): void
    {
        $provider = $this->createProvider([$this->createResponse(['id' => 8, 'login' => 'alice']), $this->createResponse(['installations' => [['id' => 4]]]), $this->createResponse(['repositories' => [['id' => 77, 'full_name' => 'owner/repo']]]), $this->createResponse(['permission' => 'admin', 'user' => ['id' => 99]])]);
        $this->expectException(ProviderUnavailableException::class);
        $provider->checkRepositoryPermissions($this->createAuthorization(), new ProviderIdentity(1, '8', 'alice', 'Alice'), new ProviderRepository(1, '77', 'owner/repo'));
    }

    /**
     * Refreshes expiring GitHub App tokens using the refresh grant.
     *
     * @return void
     */
    public function testRefreshesGitHubAppUserTokens(): void
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->once())->method('request')->with('POST', 'https://github.com/login/oauth/access_token', ['Accept' => 'application/json'], [
            'client_id' => 'client', 'client_secret' => 'fake-secret', 'grant_type' => 'refresh_token', 'refresh_token' => 'fake-refresh',
        ])->willReturn($this->createResponse(['access_token' => 'rotated-access', 'refresh_token' => 'rotated-refresh', 'token_type' => 'bearer', 'expires_in' => 28800, 'refresh_token_expires_in' => 15897600]));
        $provider = new GitHubProvider(1, new ProviderConfiguration('client', 'fake-secret', 'https://ordinal.example/callback'), $client);
        self::assertSame('rotated-refresh', $provider->refreshAuthorization($this->createAuthorization())->refreshToken);
    }

    /**
     * Refuses permissions if a mutable repository path now identifies another repository.
     *
     * @return void
     */
    public function testRejectsReusedRepositoryName(): void
    {
        $provider = $this->createProvider([$this->createResponse(['id' => 8, 'login' => 'alice']), $this->createResponse(['installations' => [['id' => 4]]]), $this->createResponse(['repositories' => [['id' => 77, 'full_name' => 'owner/repo']]]), $this->createResponse(['permission' => 'admin', 'user' => ['id' => 8]]), $this->createResponse(['id' => 99])]);
        $this->expectException(ProviderUnavailableException::class);
        $provider->checkRepositoryPermissions($this->createAuthorization(), new ProviderIdentity(1, '8', 'alice', 'Alice'), new ProviderRepository(1, '77', 'owner/repo'));
    }

    /**
     * Follows repository pages and locates the immutable ID after the first page.
     *
     * @return void
     */
    public function testFindsRepositoryOnLaterPage(): void
    {
        $repositories = [];
        for ($id = 1; $id <= 100; $id++) {
            $repositories[] = ['id' => $id, 'full_name' => 'owner/repo-' . $id];
        }
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->exactly(3))->method('request')->willReturnCallback(
            /**
             * Checks page two is requested instead of treating the first page as complete.
             *
             * @param string $method
             * @param string $url
             * @param array $headers
             * @param array $form
             * @return HttpResponse
             */
            function (string $method, string $url, array $headers, array $form) use ($repositories): HttpResponse {
                return match ($url) {
                    'https://api.github.com/user/installations?per_page=100&page=1' => $this->createResponse(['installations' => [['id' => 4]]]),
                    'https://api.github.com/user/installations/4/repositories?per_page=100&page=1' => $this->createResponse(['repositories' => $repositories]),
                    'https://api.github.com/user/installations/4/repositories?per_page=100&page=2' => $this->createResponse(['repositories' => [['id' => 177, 'full_name' => 'owner/found']]]),
                    default => self::fail('Unexpected pagination request.'),
                };
            },
        );
        $provider = new GitHubProvider(1, new ProviderConfiguration('client', 'fake-secret', 'https://ordinal.example/callback'), $client);
        self::assertSame('owner/found', $provider->findRepository($this->createAuthorization(), '177')->name);
    }

    /**
     * Bounds total discovery requests rather than allowing nested pagination to multiply indefinitely.
     *
     * @return void
     */
    public function testStopsExcessiveRepositoryDiscovery(): void
    {
        $installations = [];
        for ($id = 1; $id <= 100; $id++) {
            $installations[] = ['id' => $id];
        }
        $client = $this->createMock(HttpClient::class);
        $client->expects($this->exactly(100))->method('request')->willReturnCallback(
            /**
             * Returns a full installation page and absent repositories.
             *
             * @param string $method
             * @param string $url
             * @param array $headers
             * @param array $form
             * @return HttpResponse
             */
            function (string $method, string $url, array $headers, array $form) use ($installations): HttpResponse {
                return $this->createResponse(str_contains($url, '/repositories?') ? ['repositories' => []] : ['installations' => $installations]);
            },
        );
        $provider = new GitHubProvider(1, new ProviderConfiguration('client', 'fake-secret', 'https://ordinal.example/callback'), $client);
        $this->expectException(ProviderUnavailableException::class);
        $provider->findRepository($this->createAuthorization(), '177');
    }

    /**
     * Supplies state/challenge values that must never create an insecure login request.
     *
     * @return array
     */
    public static function provideInvalidAuthorizationRequests(): array
    {
        return [['', str_repeat('a', 43)], ['state', 'short'], ['state', str_repeat('a', 42) . '=']];
    }

    /**
     * Rejects absent state and invalid SHA-256 PKCE challenges locally.
     *
     * @param string $state
     * @param string $codeChallenge
     * @return void
     */
    #[DataProvider('provideInvalidAuthorizationRequests')]
    public function testRejectsInvalidAuthorizationRequest(string $state, string $codeChallenge): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createProvider([])->createAuthorizationUrl($state, $codeChallenge);
    }
}
