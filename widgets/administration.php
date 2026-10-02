<?php

/** Displays protected provider, administrator, and browser-policy forms without secrets. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;

$csrfToken = TemplateRenderer::escape($data['session']->csrfToken);
?>
<h2>Instance administration</h2>
<nav><a href="/account">Account</a> · <a href="/projects">Projects</a></nav>
<h3>Provider connections</h3>
<p>Registration credentials are managed by the server operator. Choose a configured registration to allow it here.</p>
<ul>
<?php foreach ($data['connections'] as $connection): ?>
    <li><?= (int) $connection['id'] ?>: <?= TemplateRenderer::escape($connection['name']) ?> (<?= $connection['disabled_at'] === null ? 'enabled' : 'disabled' ?>), <?= TemplateRenderer::escape($connection['server_url']) ?></li>
<?php endforeach; ?>
</ul>
<form method="post" action="/administration">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <input type="hidden" name="action" value="connection">
    <p><label>Registration <select name="registration"><?php foreach ($data['registrations'] as $reference): ?><option value="<?= TemplateRenderer::escape($reference) ?>"><?= TemplateRenderer::escape($reference) ?></option><?php endforeach; ?></select></label></p>
    <p><label>Connection name <input name="name" required></label></p>
    <p><label>State <select name="isEnabled"><option value="1">Enabled</option><option value="0">Disabled</option></select></label></p>
    <button type="submit">Save connection</button>
</form>
<h3>Instance administrators</h3>
<p>Sign in again within five minutes before changing access. The configured bootstrap administrator and the last administrator cannot be removed.</p>
<ul>
<?php foreach ($data['users'] as $user): ?>
    <li><?= (int) $user['id'] ?>: <?= TemplateRenderer::escape($user['display_name']) ?>, connection <?= (int) $user['provider_connection_id'] ?>, provider ID <?= TemplateRenderer::escape($user['provider_user_id']) ?><?= $user['is_administrator'] ? ' (administrator)' : '' ?></li>
<?php endforeach; ?>
</ul>
<form method="post" action="/administration">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <input type="hidden" name="action" value="administrator">
    <p><label>Local user ID <input name="userId" inputmode="numeric" required></label></p>
    <p><label>Access <select name="isAdministrator"><option value="1">Grant administration</option><option value="0">Revoke administration</option></select></label></p>
    <button type="submit">Change administrator access</button>
</form>
<h3>Browser session policy</h3>
<p>Changes apply to new sessions. Existing sessions keep their original limits.</p>
<form method="post" action="/administration">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <input type="hidden" name="action" value="sessionLimits">
    <p><label>Idle limit in minutes <input name="idleMinutes" inputmode="numeric" value="<?= (int) $data['settings']['browser_idle_minutes'] ?>" required></label></p>
    <p><label>Absolute limit in minutes <input name="absoluteMinutes" inputmode="numeric" value="<?= (int) $data['settings']['browser_absolute_minutes'] ?>" required></label></p>
    <button type="submit">Save session limits</button>
</form>
