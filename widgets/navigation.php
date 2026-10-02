<?php

/** Provides small navigation for local project management. */
declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<nav class="navigation" aria-label="Main navigation">
    <a href="/">Home</a>
    <a href="/projects">Projects</a>
    <?php if (($data['session']->isAuthenticated ?? false)): ?>
        <a href="/logs">Audit logs</a>
        <form method="post" action="/logout">
            <input type="hidden" name="csrfToken" value="<?= TemplateRenderer::escape($data['session']->csrfToken) ?>">
            <button type="submit">Sign out</button>
        </form>
    <?php else: ?><a href="/login">Sign in</a><?php endif; ?>
</nav>
