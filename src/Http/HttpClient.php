<?php

declare(strict_types=1);

namespace Ordinal\Http;

use SensitiveParameter;

/** Defines the injectable outbound transport used for provider API requests. */
abstract class HttpClient
{
    /**
     * Sends a GET or form-encoded POST without following redirects.
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param array $form
     * @return HttpResponse
     * @throws \Ordinal\Provider\ProviderUnavailableException
     */
    abstract public function request(
        string $method,
        string $url,
        #[SensitiveParameter]
        array  $headers = [],
        #[SensitiveParameter]
        array  $form    = [],
    ): HttpResponse;
}
