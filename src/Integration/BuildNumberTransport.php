<?php

declare(strict_types=1);

namespace Ordinal\Integration;

use Ordinal\Http\HttpResponse;
use SensitiveParameter;

/** Defines injectable bounded JSON allocation transport for the reusable build helper. */
abstract class BuildNumberTransport
{
    /**
     * Sends one HTTPS allocation with JSON and optional Bearer authentication.
     *
     * @param string $url
     * @param string $requestId
     * @param ?string $token
     * @param int $timeoutSeconds
     * @return HttpResponse
     * @throws BuildTransportException
     */
    abstract public function requestAllocation(
        string  $url,
        string  $requestId,
        #[SensitiveParameter]
        ?string $token,
        int     $timeoutSeconds,
    ): HttpResponse;
}
