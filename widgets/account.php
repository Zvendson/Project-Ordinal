<?php

/** Displays the provider-qualified account and CSRF-protected session actions. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;

$session = $data['session'];
?>
<h2>Account</h2>
<p>Signed in as <?= TemplateRenderer::escape($session->displayName) ?>.</p>
<p>Provider connection: <?= $session->providerConnectionId ?>. Provider user ID: <?= TemplateRenderer::escape($session->providerUserId) ?>.</p>
<p>Session ends at <?= TemplateRenderer::escape($session->expiresAt->format('Y-m-d H:i:s T')) ?>, or earlier when inactive.</p>
<form method="post" action="/account/reauthenticate">
    <input type="hidden" name="csrfToken" value="<?= TemplateRenderer::escape($session->csrfToken) ?>">
    <p><button type="submit">Sign in again for sensitive changes</button></p>
</form>
<form method="post" action="/account/logout">
    <input type="hidden" name="csrfToken" value="<?= TemplateRenderer::escape($session->csrfToken) ?>">
    <p><button type="submit">Sign out</button></p>
</form>
