<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Http;

use InvalidArgumentException;
use Ordinal\Http\ApiError;
use PHPUnit\Framework\TestCase;

/** Verifies the agreed error envelope and status mappings. */
final class ApiErrorTest extends TestCase
{
    /**
     * Produces stable codes and readable messages for API failures.
     *
     * @return void
     */
    public function testCreatesAgreedErrors(): void
    {
        foreach (['INVALID_REQUEST' => 400, 'INVALID_AUTHENTICATION' => 401,
            'ACCESS_DENIED' => 403, 'PROJECT_NOT_FOUND' => 404,
            'BUILD_COUNTER_EXHAUSTED' => 409, 'SERVICE_UNAVAILABLE' => 503,
            'NOT_FOUND' => 404, 'METHOD_NOT_ALLOWED' => 405, 'INTERNAL_ERROR' => 500] as $code => $status) {
            $response = ApiError::createResponse($code);
            $data = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($status, $response->statusCode);
            self::assertSame(['error'], array_keys($data));
            self::assertSame($code, $data['error']['code']);
            self::assertNotEmpty($data['error']['message']);
        }
    }

    /**
     * Rejects undefined codes instead of silently assigning the wrong status.
     *
     * @return void
     */
    public function testRejectsUnknownCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApiError::createResponse('UNDEFINED');
    }
}
