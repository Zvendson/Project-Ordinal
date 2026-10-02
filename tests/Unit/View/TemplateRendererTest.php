<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\View;

use Ordinal\View\TemplateRenderer;
use PHPUnit\Framework\TestCase;

/** Verifies unstyled HTML rendering and escaping. */
final class TemplateRendererTest extends TestCase
{
    /**
     * Displays the full product name in semantic HTML.
     *
     * @return void
     */
    public function testRendersHome(): void
    {
        $html = (new TemplateRenderer())->renderHome();
        self::assertStringContainsString('<h1>Project: Ordinal</h1>', $html);
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringNotContainsString('<style', $html);
    }

    /**
     * Escapes error content so it cannot become executable markup.
     *
     * @return void
     */
    public function testEscapesErrorMessages(): void
    {
        $html = (new TemplateRenderer())->renderError('<script>alert("x")</script>');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
    }
}
