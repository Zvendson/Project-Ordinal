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

    /** Keeps browser navigation permission-aware and renders secrets only in the response. @return void */
    public function testRendersAuthenticatedNavigationAndSharedSecret(): void
    {
        $session = new \Ordinal\Model\BrowserSession(1, 1, 1, '8', '<Alice>', 'csrf', null, new \DateTimeImmutable('+1 hour'));
        $html = (new TemplateRenderer())->renderAccountPage('account', ['session' => $session, 'isAdministrator' => true]);
        self::assertStringContainsString('href="/administration"', $html);
        self::assertStringContainsString('href="/devices"', $html);
        self::assertStringContainsString('&lt;Alice&gt;', $html);
        $secret = (new TemplateRenderer())->renderAccountPage('automation-secret', ['id' => 1, 'name' => 'CI', 'projectId' => 1, 'token' => '<secret>', 'expiresAt' => null]);
        self::assertStringContainsString('readonly', $secret);
        self::assertStringContainsString('data-copy-secret', $secret);
        self::assertStringContainsString('&lt;secret&gt;', $secret);
        self::assertStringContainsString('role="status"', $secret);
    }
}
