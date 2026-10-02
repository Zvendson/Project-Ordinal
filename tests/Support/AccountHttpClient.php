<?php

declare(strict_types=1);

namespace Ordinal\Tests\Support;

use Ordinal\Http\HttpClient;
use Ordinal\Http\HttpResponse;
use SensitiveParameter;

/** Provides a shared deterministic provider transport for the guarded account fixture. */
final class AccountHttpClient extends HttpClient
{
    /**
     * Delegates current provider state to the fixture.
     *
     * @param AccountFixture $fixture
     */
    public function __construct(
        /** Supplies fake roles and provider responses. */
        private readonly AccountFixture $fixture,
    ) {}

    /**
     * Returns a controlled response without network access.
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param array $form
     * @return HttpResponse
     */
    public function request(
        string $method,
        string $url,
        #[SensitiveParameter]
        array  $headers = [],
        #[SensitiveParameter]
        array  $form    = [],
    ): HttpResponse
    {
        return $this->fixture->respond($method, $url, $headers, $form);
    }
}
