<?php

declare(strict_types=1);

namespace Ordinal\Http;

use CurlHandle;
use InvalidArgumentException;
use Ordinal\Model\ProviderConfiguration;
use Ordinal\Provider\ProviderUnavailableException;
use SensitiveParameter;

/** Sends bounded HTTPS requests with certificate verification and no redirect forwarding. */
final class CurlHttpClient extends HttpClient
{
    /** Limits the time spent connecting to a provider. */
    private const int CONNECT_TIMEOUT_SECONDS = 5;
    /** Limits the total time spent on one provider request. */
    private const int REQUEST_TIMEOUT_SECONDS = 20;
    /** Bounds memory used by an upstream response. */
    private const int MAX_RESPONSE_BYTES = 4_194_304;

    /**
     * Sends a validated request while keeping transport errors secret-free.
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param array $form
     * @return HttpResponse
     * @throws InvalidArgumentException
     * @throws ProviderUnavailableException
     */
    public function request(
        string $method,
        string $url,
        #[SensitiveParameter]
        array  $headers = [],
        #[SensitiveParameter]
        array  $form    = [],
    ): HttpResponse {
        ProviderConfiguration::assertSecureUrl($url);
        if (!in_array($method, ['GET', 'POST'], true) || ($method === 'GET' && $form !== [])) {
            throw new InvalidArgumentException('Provider requests must use GET or form-encoded POST.');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z0-9-]+$/D', $name) !== 1
                || !is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('Provider request headers must be valid single-line values.');
            }
            $headerLines[] = $name . ': ' . $value;
        }
        foreach ($form as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new InvalidArgumentException('Provider form fields must be strings.');
            }
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ProviderUnavailableException('Provider request could not be completed.');
        }
        $body = '';
        try {
            curl_setopt_array($handle, [
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
                CURLOPT_USERAGENT => 'Project-Ordinal',
                CURLOPT_WRITEFUNCTION =>
                    /**
                     * Collects a bounded body and aborts oversized responses.
                     *
                     * @param CurlHandle $handle
                     * @param string $chunk
                     * @return int
                     */
                    static function (CurlHandle $handle, string $chunk) use (&$body): int {
                        if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                            return 0;
                        }
                        $body .= $chunk;
                        return strlen($chunk);
                    },
            ]);
            if ($method === 'POST') {
                curl_setopt($handle, CURLOPT_POST, true);
                curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
            }
            if (curl_exec($handle) === false) {
                throw new ProviderUnavailableException('Provider request could not be completed.');
            }
            return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body);
        } finally {
            unset($handle);
        }
    }
}
