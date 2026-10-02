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

    /** Verifies keyboard navigation, compiled assets and a named error without injected markup. @return void */
    public function testRendersAccessiblePageShell(): void
    {
        $html = (new TemplateRenderer())->renderHome();
        self::assertStringContainsString('href="#main-content"', $html);
        self::assertStringContainsString('aria-label="Main navigation"', $html);
        self::assertStringContainsString('href="/assets/app.css"', $html);
        self::assertStringContainsString('type="module" src="/assets/scripts/app.js"', $html);
        self::assertStringNotContainsString('href="/administration"', $html);
        self::assertStringContainsString('role="alert"', (new TemplateRenderer())->renderError('Sign in again.'));
    }

    /** Escapes one-time token values and rejects unknown template names. */
    public function testEscapesIssuedSecrets(): void
    {
        $session = new \Ordinal\Model\AdministratorSession(1, 'csrf', true);
        $html = (new TemplateRenderer())->renderAccountPage('token-secret', ['session' => $session, 'name' => '<Laptop>', 'projectId' => 1, 'token' => '<script>secret</script>']);
        self::assertStringContainsString('&lt;script&gt;secret&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>secret', $html);
        self::assertStringContainsString('data-copy-secret', $html);
    }
}
