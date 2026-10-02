<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Http;

use JsonException;
use Ordinal\Http\Response;
use PHPUnit\Framework\TestCase;

/** Verifies JSON serialization and response metadata. */
final class ResponseTest extends TestCase
{
    /**
     * Serializes numeric values without converting them to strings.
     *
     * @return void
     */
    public function testCreatesJsonResponse(): void
    {
        $response = Response::createJson(['buildNumber' => 4294967295]);
        self::assertSame(200, $response->statusCode);
        self::assertSame(['buildNumber' => 4294967295], json_decode($response->body, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('application/json; charset=UTF-8', $response->headers['Content-Type']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    /**
     * Rejects values that cannot be represented as JSON.
     *
     * @return void
     */
    public function testRejectsInvalidJsonValues(): void
    {
        $this->expectException(JsonException::class);
        Response::createJson(['invalid' => NAN]);
    }
}
