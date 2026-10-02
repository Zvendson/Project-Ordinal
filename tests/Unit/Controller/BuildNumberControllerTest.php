<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Controller;

use Ordinal\Controller\BuildNumberController;
use Ordinal\Security\AllocationAuthorizer;
use Ordinal\Security\AuthenticationException;
use PHPUnit\Framework\TestCase;

/** Verifies allocation input validation and the authorization boundary. */
final class BuildNumberControllerTest extends TestCase
{
    /**
     * Keeps allocation unavailable even when a test authorizer accepts a request.
     *
     * @return void
     */
    public function testDoesNotAllocateBeforeServiceExists(): void
    {
        $authorizer = $this->createMock(AllocationAuthorizer::class);
        $authorizer->expects(self::once())->method('authorizeAllocation');
        $this->expectException(\LogicException::class);
        (new BuildNumberController($authorizer))->createBuildNumber('1', self::REQUEST_BODY, null, 'application/json');
    }

    /** Names a valid UUID v4 request for these tests. */
    private const string REQUEST_BODY = '{"requestId":"11111111-1111-4111-8111-111111111111"}';

    /**
     * Rejects valid requests while credential support is unavailable.
     *
     * @return void
     */
    public function testDeniesRequestsByDefault(): void
    {
        foreach ([null, 'Bearer unknown-secret', 'Basic unknown-secret', 'Bearer', 'Bearer token with spaces'] as $header) {
            $response = (new BuildNumberController())->createBuildNumber('1', self::REQUEST_BODY, $header, 'application/json');
            self::assertSame(401, $response->statusCode);
            self::assertSame('INVALID_AUTHENTICATION', json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['error']['code']);
            self::assertStringNotContainsString('unknown-secret', $response->body);
        }
    }

    /**
     * Rejects malformed JSON and invalid UUID shapes before invoking authorization.
     *
     * @return void
     */
    public function testRejectsInvalidRequestBodies(): void
    {
        $authorizer = $this->createMock(AllocationAuthorizer::class);
        $authorizer->expects(self::never())->method('authorizeAllocation');
        $controller = new BuildNumberController($authorizer);
        foreach (['', '{', '[]', 'null', '{}', '{"requestId":123}', '{"requestId":[]}',
            '{"requestId":"11111111-1111-1111-8111-111111111111"}',
            '{"requestId":"11111111-1111-4111-7111-111111111111"}',
            '{"requestId":"not-a-uuid"}'] as $body) {
            $response = $controller->createBuildNumber('1', $body, null, 'application/json');
            self::assertSame(400, $response->statusCode, $body);
            self::assertSame('INVALID_REQUEST', json_decode($response->body, true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        }
    }

    /**
     * Rejects unsafe or out-of-range project identifiers and non-JSON content types.
     *
     * @return void
     */
    public function testRejectsInvalidRequestMetadata(): void
    {
        foreach (['0', '-1', '01', '1.5', 'abc', '9223372036854775808'] as $projectId) {
            self::assertSame(400, (new BuildNumberController())->createBuildNumber($projectId, self::REQUEST_BODY, null, 'application/json')->statusCode);
        }
        foreach ([null, 'text/plain', 'application/x-www-form-urlencoded'] as $type) {
            self::assertSame(400, (new BuildNumberController())->createBuildNumber('1', self::REQUEST_BODY, null, $type)->statusCode);
        }
    }

    /**
     * Passes the validated project and Bearer secret to authorization.
     *
     * @return void
     */
    public function testCallsAuthorizerWithValidatedRequest(): void
    {
        $authorizer = $this->createMock(AllocationAuthorizer::class);
        $authorizer->expects(self::once())->method('authorizeAllocation')->with(12, 'test-secret')
            ->willThrowException(new AuthenticationException());
        $response = (new BuildNumberController($authorizer))->createBuildNumber('12', self::REQUEST_BODY, 'Bearer test-secret', 'application/json; charset=utf-8');
        self::assertSame(401, $response->statusCode);
    }
}
