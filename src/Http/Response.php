<?php

declare(strict_types=1);

namespace Ordinal\Http;

use JsonException;

/** Carries response data without sending headers or writing output. */
final readonly class Response
{
    /** Indicates successful request handling. */
    public const int STATUS_OK = 200;
    /** Indicates malformed request input. */
    public const int STATUS_BAD_REQUEST = 400;
    /** Indicates missing or invalid required authentication. */
    public const int STATUS_UNAUTHORIZED = 401;
    /** Indicates that an authenticated operation is not permitted. */
    public const int STATUS_FORBIDDEN = 403;
    /** Indicates that the requested path has no route. */
    public const int STATUS_NOT_FOUND = 404;
    /** Indicates that the path exists but does not support the request method. */
    public const int STATUS_METHOD_NOT_ALLOWED = 405;
    /** Indicates a conflict with the current counter state. */
    public const int STATUS_CONFLICT = 409;
    /** Indicates an unexpected application failure. */
    public const int STATUS_INTERNAL_SERVER_ERROR = 500;
    /** Indicates temporary unavailability of provider verification. */
    public const int STATUS_SERVICE_UNAVAILABLE = 503;
    /** Identifies the initial plain-text response format. */
    public const array TEXT_HEADERS = ['Content-Type' => 'text/plain; charset=UTF-8'];

    /**
     * Stores the status, body, and headers supplied by application routing.
     *
     * @param int $statusCode
     * @param string $body
     * @param array $headers
     */
    public function __construct(
        /** Contains the HTTP status code. */
        public int    $statusCode,
        /** Contains the response content. */
        public string $body,
        /** Maps response header names to their values. */
        public array  $headers = self::TEXT_HEADERS,
    ) {}

    /**
     * Encodes JSON while preserving numeric values and preventing response caching.
     *
     * @param array $data
     * @param int $statusCode
     * @param array $headers
     * @return self
     * @throws JsonException
     */
    public static function createJson(
        array $data,
        int   $statusCode = self::STATUS_OK,
        array $headers    = [],
    ): self {
        return new self($statusCode, json_encode($data, JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store'] + $headers,
        );
    }

    /**
     * Creates an uncached HTML response from already rendered markup.
     *
     * @param string $html
     * @param int $statusCode
     * @param array $headers
     * @return self
     */
    public static function createHtml(
        string $html,
        int    $statusCode = self::STATUS_OK,
        array  $headers    = [],
    ): self {
        return new self($statusCode, $html,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'] + $headers,
        );
    }
}
