<?php

declare(strict_types=1);

namespace Ordinal\Integration;

use CurlHandle;
use Ordinal\Http\HttpResponse;
use Ordinal\Model\RequestId;
use SensitiveParameter;

/** Sends bounded HTTPS JSON allocation requests without redirects or credential-bearing diagnostics. */
final class CurlBuildNumberTransport extends BuildNumberTransport
{
    /** Bounds connection establishment within the total request timeout. */
    private const int CONNECT_TIMEOUT_SECONDS = 10;
    /** Enforces the agreed 30-second request maximum. */
    private const int MAX_TIMEOUT_SECONDS = 30;
    /** Bounds response memory well above the small allocation response size. */
    private const int MAX_RESPONSE_BYTES = 65_536;

    /**
     * Sends one request with certificate verification; transport failures contain no curl details.
     *
     * @param string $url
     * @param string $requestId
     * @param ?string $token
     * @param int $timeoutSeconds
     * @return HttpResponse
     */
    public function requestAllocation(
        string  $url,
        string  $requestId,
        #[SensitiveParameter]
        ?string $token,
        int     $timeoutSeconds,
    ): HttpResponse {
        BuildRequestStore::normalizeServerUrl($url);
        if (!RequestId::isValid($requestId) || $timeoutSeconds < 1 || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS
            || ($token !== null && preg_match('/^[A-Za-z0-9\-._~+\/]+=*$/D', $token) !== 1)) {
            throw new BuildIntegrationException('Invalid build-number transport input.');
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new BuildTransportException();
        }
        $body = '';
        $isResponseTooLarge = false;
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['requestId' => $requestId], JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT_SECONDS, $timeoutSeconds),
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_USERAGENT => 'Project-Ordinal-Build',
                CURLOPT_WRITEFUNCTION =>
                    /**
                     * Collects a bounded response without retaining transport diagnostics.
                     *
                     * @param CurlHandle $handle
                     * @param string $chunk
                     * @return int
                     */
                    static function (CurlHandle $handle, string $chunk) use (&$body, &$isResponseTooLarge): int {
                        if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                            $isResponseTooLarge = true;
                            return 0;
                        }
                        $body .= $chunk;
                        return strlen($chunk);
                    },
            ]);
            $isCompleted = curl_exec($handle) !== false;
            if ($isResponseTooLarge) {
                throw new BuildIntegrationException('The allocation response exceeded its size limit. Preserve the pending request ID.');
            }
            if (!$isCompleted) {
                throw new BuildTransportException();
            }
            return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $body);
        } finally {
            unset($handle);
        }
    }
}
