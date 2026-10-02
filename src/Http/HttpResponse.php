<?php

declare(strict_types=1);

namespace Ordinal\Http;

use SensitiveParameter;

/** Holds an upstream HTTP status and raw body, including sensitive token responses. */
final readonly class HttpResponse
{
    /**
     * Captures the transport result for provider-specific interpretation.
     *
     * @param int $statusCode
     * @param string $body
     */
    public function __construct(
        /** Stores the upstream HTTP status code. */
        public int    $statusCode,
        /** Stores the raw response without logging provider credentials. */
        #[SensitiveParameter]
        public string $body,
    ) {}
}
