<?php

/** Dispatches web requests and sends the response from the public entry point. */

declare(strict_types=1);

use Ordinal\Http\ApiError;
use Ordinal\Http\Response;
use Ordinal\Http\Router;

ini_set('display_errors', '0');

require dirname(__DIR__) . '/vendor/autoload.php';

$method        = $_SERVER['REQUEST_METHOD'] ?? '';
$requestTarget = $_SERVER['REQUEST_URI'] ?? '';

try {
    $response = (new Router())->dispatch($method, $requestTarget);
} catch (Throwable) {
    $path = explode('?', $requestTarget, 2)[0];
    $response = $path === '/api' || str_starts_with($path, '/api/')
        ? ApiError::createResponse('INTERNAL_ERROR')
        : new Response(Response::STATUS_INTERNAL_SERVER_ERROR, 'An unexpected error occurred.');
}

http_response_code($response->statusCode);

foreach ($response->headers as $headerName => $headerValue) {
    header($headerName . ': ' . $headerValue);
}

echo $response->body;
