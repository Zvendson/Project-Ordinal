<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Provider;

use InvalidArgumentException;
use RuntimeException;
use Ordinal\Model\ProviderAuthorization;
use Ordinal\Model\ProviderIdentity;
use Ordinal\Model\ProviderRepository;
use Ordinal\Model\RepositoryPermissions;
use Ordinal\Provider\Provider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the behavior of Provider.
 */
final class ProviderTest extends TestCase
{
    /**
     * Verifies: checks permissions with the concrete provider.
     *
     * @return void
     */
    public function testChecksPermissionsWithTheConcreteProvider(): void
    {
        $authorization = new ProviderAuthorization(1, 'test-access-token', null, null);
        $identity = new ProviderIdentity(1, '42', 'developer', 'Developer');
        $repository = new ProviderRepository(1, '100', 'Example');
        $permissions = new RepositoryPermissions(true, false);
        $provider = $this->getMockBuilder(Provider::class)->setConstructorArgs([1])->getMock();
        $provider->expects(self::once())->method('findIdentity')->with($authorization)->willReturn($identity);
        $provider->expects(self::once())->method('fetchRepositoryPermissions')
            ->with($authorization, $identity, $repository)->willReturn($permissions);

        self::assertSame($permissions, $provider->checkRepositoryPermissions($authorization, $identity, $repository));
        self::assertSame(1, $provider->getProviderConnectionId());
    }

    /**
     * Verifies: rejects records from another connection.
     *
     * @param int $authorizationConnectionId
     * @param int $identityConnectionId
     * @param int $repositoryConnectionId
     * @return void
     */
    #[DataProvider('provideForeignConnections')]
    public function testRejectsRecordsFromAnotherConnection(
        int $authorizationConnectionId,
        int $identityConnectionId,
        int $repositoryConnectionId,
    ): void {
        $authorization = new ProviderAuthorization($authorizationConnectionId, 'test-access-token', null, null);
        $identity = new ProviderIdentity($identityConnectionId, '42', 'developer', 'Developer');
        $repository = new ProviderRepository($repositoryConnectionId, '100', 'Example');
        $provider = $this->getMockBuilder(Provider::class)->setConstructorArgs([1])->getMock();
        $provider->expects(self::never())->method('findIdentity');
        $provider->expects(self::never())->method('fetchRepositoryPermissions');

        $this->expectException(InvalidArgumentException::class);
        $provider->checkRepositoryPermissions($authorization, $identity, $repository);
    }

    /**
     * Supplies mismatched authorization, identity, and repository connections.
     *
     * @return array
     */
    public static function provideForeignConnections(): array
    {
        return [
            'authorization' => [2, 1, 1],
            'identity' => [1, 2, 1],
            'repository' => [1, 1, 2],
        ];
    }

    /**
     * Verifies: rejects invalid connection ids.
     *
     * @param int $providerConnectionId
     * @return void
     */
    #[DataProvider('provideInvalidConnectionIds')]
    public function testRejectsInvalidConnectionIds(int $providerConnectionId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->getMockBuilder(Provider::class)->setConstructorArgs([$providerConnectionId])->getMock();
    }

    /**
     * Supplies connection IDs that cannot identify persisted provider connections.
     *
     * @return array
     */
    public static function provideInvalidConnectionIds(): array
    {
        return [[0], [-1]];
    }

    /**
     * Verifies: rejects an authorization for a different identity.
     *
     * @param int $verifiedConnectionId
     * @param string $verifiedUserId
     * @return void
     */
    #[DataProvider('provideUnexpectedIdentities')]
    public function testRejectsAnAuthorizationForADifferentIdentity(int $verifiedConnectionId, string $verifiedUserId): void
    {
        $authorization = new ProviderAuthorization(1, 'test-access-token', null, null);
        $identity = new ProviderIdentity(1, '42', 'developer', 'Developer');
        $repository = new ProviderRepository(1, '100', 'Example');
        $verifiedIdentity = new ProviderIdentity($verifiedConnectionId, $verifiedUserId, 'developer', 'Developer');
        $provider = $this->getMockBuilder(Provider::class)->setConstructorArgs([1])->getMock();
        $provider->expects(self::once())->method('findIdentity')->with($authorization)->willReturn($verifiedIdentity);
        $provider->expects(self::never())->method('fetchRepositoryPermissions');

        $this->expectException(InvalidArgumentException::class);
        $provider->checkRepositoryPermissions($authorization, $identity, $repository);
    }

    /**
     * Supplies token-holder identities that do not match the requested caller.
     *
     * @return array
     */
    public static function provideUnexpectedIdentities(): array
    {
        return [
            'same name but different user ID' => [1, '99'],
            'same user ID but different connection' => [2, '42'],
        ];
    }

    /**
     * Verifies: uses the verified identity after a user name changes.
     *
     * @return void
     */
    public function testUsesTheVerifiedIdentityAfterAUserNameChanges(): void
    {
        $authorization = new ProviderAuthorization(1, 'test-access-token', null, null);
        $identity = new ProviderIdentity(1, '42', 'old-name', 'Developer');
        $verifiedIdentity = new ProviderIdentity(1, '42', 'new-name', 'Developer');
        $repository = new ProviderRepository(1, '100', 'Example');
        $permissions = new RepositoryPermissions(true, false);
        $provider = $this->getMockBuilder(Provider::class)->setConstructorArgs([1])->getMock();
        $provider->expects(self::once())->method('findIdentity')->willReturn($verifiedIdentity);
        $provider->expects(self::once())->method('fetchRepositoryPermissions')
            ->with($authorization, $verifiedIdentity, $repository)->willReturn($permissions);

        self::assertSame($permissions, $provider->checkRepositoryPermissions($authorization, $identity, $repository));
    }

    /**
     * Verifies: does not check permissions when identity verification fails.
     *
     * @return void
     */
    public function testDoesNotCheckPermissionsWhenIdentityVerificationFails(): void
    {
        $authorization = new ProviderAuthorization(1, 'test-access-token', null, null);
        $identity = new ProviderIdentity(1, '42', 'developer', 'Developer');
        $repository = new ProviderRepository(1, '100', 'Example');
        $provider = $this->getMockBuilder(Provider::class)->setConstructorArgs([1])->getMock();
        $provider->expects(self::once())->method('findIdentity')
            ->willThrowException(new RuntimeException('Provider verification unavailable.'));
        $provider->expects(self::never())->method('fetchRepositoryPermissions');

        $this->expectException(RuntimeException::class);
        $provider->checkRepositoryPermissions($authorization, $identity, $repository);
    }

    /**
     * Verifies: propagates verification failure without returning permissions.
     *
     * @return void
     */
    public function testPropagatesVerificationFailureWithoutReturningPermissions(): void
    {
        $authorization = new ProviderAuthorization(1, 'test-access-token', null, null);
        $identity = new ProviderIdentity(1, '42', 'developer', 'Developer');
        $repository = new ProviderRepository(1, '100', 'Example');
        $provider = $this->getMockBuilder(Provider::class)->setConstructorArgs([1])->getMock();
        $provider->method('findIdentity')->willReturn($identity);
        $provider->expects(self::once())->method('fetchRepositoryPermissions')
            ->willThrowException(new RuntimeException('Provider verification unavailable.'));

        $this->expectException(RuntimeException::class);
        $provider->checkRepositoryPermissions($authorization, $identity, $repository);
    }
}
