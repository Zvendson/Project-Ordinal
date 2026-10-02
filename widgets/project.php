<?php

/** Shows a linked project only after current contributor or administrator access is verified. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<h2><?= TemplateRenderer::escape($data['project']['name']) ?></h2>
<p>Provider connection: <?= (int) $data['project']['provider_connection_id'] ?>. Repository ID: <?= TemplateRenderer::escape($data['project']['provider_repository_id']) ?>.</p>
<p>Current access: <?= $data['permissions']->canAdministerProject ? 'project administration' : 'contributor' ?>.</p>
<h3>Build authentication</h3>
<p>Authentication: <?= $data['project']['is_authentication_required'] ? 'required' : 'disabled (anonymous allocation)' ?>. Effective device lifetime: <?= (int) $data['project']['device_lifetime_days'] ?> days.</p>
<p>Positive days expire from provider sign-in. 0 allows one allocation and its replay. -1 has no time expiration. Policy changes apply to new credentials; existing credentials keep their assigned expiration.</p>
<p><a href="/devices?projectId=<?= (int) $data['project']['id'] ?>">Manage device credentials for this project</a></p>
<?php if ($data['isAdministrator']): ?>
<form method="post" action="/devices/policy">
    <input type="hidden" name="csrfToken" value="<?= TemplateRenderer::escape($data['session']->csrfToken) ?>">
    <input type="hidden" name="projectId" value="<?= (int) $data['project']['id'] ?>">
    <p><label>Authentication override <select name="isRequired">
        <option value="inherit"<?= $data['project']['authentication_required_override'] === null ? ' selected' : '' ?>>Inherit instance default</option>
        <option value="1"<?= $data['project']['authentication_required_override'] === true ? ' selected' : '' ?>>Required</option>
        <option value="0"<?= $data['project']['authentication_required_override'] === false ? ' selected' : '' ?>>Disabled</option>
    </select></label></p>
    <p><label>Device lifetime override <input name="lifetimeDays" value="<?= $data['project']['device_lifetime_days_override'] === null ? 'inherit' : (int) $data['project']['device_lifetime_days_override'] ?>" required></label> (inherit, -1, 0, or positive days)</p>
    <button type="submit">Save project authentication settings</button>
</form>
<?php endif; ?>
<p><a href="/projects">Projects</a> · <a href="/account">Account</a></p>
