<?php

declare(strict_types=1);

namespace Ordinal\Http;

use InvalidArgumentException;

/** Creates the agreed public API errors without accepting internal exception details. */
final class ApiError
{
    /** Maps stable error codes to HTTP statuses and public messages. */
    private const array ERRORS = [
        'INVALID_REQUEST' => [Response::STATUS_BAD_REQUEST, 'The request is invalid.'],
        'INVALID_AUTHENTICATION' => [Response::STATUS_UNAUTHORIZED, 'Authentication is required or invalid.'],
        'ACCESS_DENIED' => [Response::STATUS_FORBIDDEN, 'Access is denied.'],
        'PROJECT_NOT_FOUND' => [Response::STATUS_NOT_FOUND, 'Project not found.'],
        'BUILD_COUNTER_EXHAUSTED' => [Response::STATUS_CONFLICT, 'The build counter is exhausted.'],
        'PROVIDER_UNAVAILABLE' => [Response::STATUS_SERVICE_UNAVAILABLE, 'Provider verification is temporarily unavailable.'],
        'NOT_FOUND' => [Response::STATUS_NOT_FOUND, 'Endpoint not found.'],
        'METHOD_NOT_ALLOWED' => [Response::STATUS_METHOD_NOT_ALLOWED, 'Method not allowed.'],
        'INTERNAL_ERROR' => [Response::STATUS_INTERNAL_SERVER_ERROR, 'An unexpected error occurred.'],
    ];

    /**
     * Returns the stable error envelope with optional routing headers.
     *
     * @param string $code
     * @param array $headers
     * @return Response
     * @throws InvalidArgumentException
     */
    public static function createResponse(string $code, array $headers = []): Response
    {
        if (!isset(self::ERRORS[$code])) {
            throw new InvalidArgumentException('Unknown API error code.');
        }

        [$statusCode, $message] = self::ERRORS[$code];
        return Response::createJson(['error' => ['code' => $code, 'message' => $message]], $statusCode, $headers);
    }
}
