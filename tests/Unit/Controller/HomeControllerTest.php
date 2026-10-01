<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Controller;

use Ordinal\Controller\HomeController;
use PHPUnit\Framework\TestCase;

/** Verifies the initial home response before browser templates are added. */
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
        self::assertSame('Project: Ordinal', $response->body);
        self::assertSame('text/plain; charset=UTF-8', $response->headers['Content-Type']);
    }
}
