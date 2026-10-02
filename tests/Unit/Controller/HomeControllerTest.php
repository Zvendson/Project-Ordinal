<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Controller;

use Ordinal\Controller\HomeController;
use PHPUnit\Framework\TestCase;

/** Verifies the initial unstyled home response. */
final class HomeControllerTest extends TestCase
{
    /**
     * Displays the established product name.
     *
     * @return void
     */
    public function testShowsProductName(): void
    {
        $response = (new HomeController())->showHome();

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('<h1>Project: Ordinal</h1>', $response->body);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
    }
}
