<?php

/** Displays protected provider, administrator, and browser-policy forms without secrets. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;

$csrfToken = TemplateRenderer::escape($data['session']->csrfToken);
?>
<h2>Instance administration</h2>
<nav><a href="/account">Account</a> · <a href="/projects">Projects</a> · <a href="/devices">Devices</a></nav>
<h3>Default build authentication</h3>
<p>Projects inherit these settings unless they have an override. Lifetime changes apply to new credentials. Positive days expire from provider sign-in; 0 allows one allocation and its replay; -1 has no time expiration.</p>
<form method="post" action="/devices/policy">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <input type="hidden" name="projectId" value="">
    <p><label>Authentication <select name="isRequired"><option value="1"<?= $data['settings']['is_authentication_required'] ? ' selected' : '' ?>>Required</option><option value="0"<?= !$data['settings']['is_authentication_required'] ? ' selected' : '' ?>>Disabled</option></select></label></p>
    <p><label>Device lifetime in days <input name="lifetimeDays" value="<?= (int) $data['settings']['device_lifetime_days'] ?>" required></label></p>
    <button type="submit">Save authentication defaults</button>
</form>
<h3>Automation token policy</h3>
<p>Changes apply to creation and rotation. Existing tokens retain their expiry. Device authentication lifetime is separate.</p>
<form method="post" action="/automation/policy">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <p><label>Default lifetime in days <input name="lifetimeDays" value="<?= (int) $data['settings']['automation_lifetime_days'] ?>" inputmode="numeric" required></label></p>
    <p><label>Tokens without expiration <select name="isWithoutExpirationAllowed"><option value="0"<?= !$data['settings']['is_automation_without_expiration_allowed'] ? ' selected' : '' ?>>Disabled</option><option value="1"<?= $data['settings']['is_automation_without_expiration_allowed'] ? ' selected' : '' ?>>Allowed</option></select></label></p>
    <button type="submit">Save automation policy</button>
</form>
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
