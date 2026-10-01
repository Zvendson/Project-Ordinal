<?php

/** Dispatches web requests and sends the response from the public entry point. */

declare(strict_types=1);

use Ordinal\Http\Router;

require dirname(__DIR__) . '/vendor/autoload.php';

$method        = $_SERVER['REQUEST_METHOD'] ?? '';
$requestTarget = $_SERVER['REQUEST_URI'] ?? '';
$response      = (new Router())->dispatch($method, $requestTarget);

http_response_code($response->statusCode);

foreach ($response->headers as $headerName => $headerValue) {
    header($headerName . ': ' . $headerValue);
}

echo $response->body;
