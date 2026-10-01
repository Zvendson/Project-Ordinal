<?php

declare(strict_types=1);

namespace Ordinal\Http;

/** Carries response data without sending headers or writing output. */
final readonly class Response
{
    /** Indicates successful request handling. */
    public const int STATUS_OK = 200;
    /** Indicates that the requested path has no route. */
    public const int STATUS_NOT_FOUND = 404;
    /** Indicates that the path exists but does not support the request method. */
    public const int STATUS_METHOD_NOT_ALLOWED = 405;
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
}
