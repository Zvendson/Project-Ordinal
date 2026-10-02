<?php

/** Displays the single administrator password form with browser-bound CSRF. */
declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<h2>Administrator sign in</h2>
<p>Use the administrator password configured for this installation.</p>
<form method="post" action="/login">
    <input type="hidden" name="csrfToken" value="<?= TemplateRenderer::escape($data['session']->csrfToken) ?>">
    <p><label>Administrator password <input type="password" name="password" autocomplete="current-password" required></label></p>
    <button type="submit">Sign in</button>
</form>
