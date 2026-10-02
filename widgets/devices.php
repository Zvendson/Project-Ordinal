<?php

/** Displays device identities, metadata, and protected enrollment/revocation forms. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;

$csrfToken = TemplateRenderer::escape($data['session']->csrfToken);
?>
<h2>Devices</h2>
<h3>Approve a project credential</h3>
<p>Use a new readable device name, or enter an existing device ID to keep its identity. Each credential belongs to one project. The original secret is shown once.</p>
<p>A lifetime of 0 requires a provider sign-in within five minutes, once per project and sign-in. <a href="/account">Sign in again</a> before approving the next single-allocation credential.</p>
<form method="post" action="/devices/enroll">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <p><label>Project ID <input name="projectId" inputmode="numeric" value="<?= $data['projectId'] === null ? '' : (int) $data['projectId'] ?>" required></label></p>
    <p><label>New device name <input name="name" maxlength="200"></label></p>
    <p><label>Existing device ID (optional) <input name="deviceId" inputmode="numeric"></label></p>
    <button type="submit">Approve credential</button>
</form>
<h3>Device identities</h3>
<?php foreach ($data['devices'] as $device): ?>
<p>Device <?= (int) $device['id'] ?>: <?= TemplateRenderer::escape($device['name']) ?>, owner <?= (int) $device['user_id'] ?><?= $device['revoked_at'] === null ? '' : ' (revoked)' ?>.</p>
<?php if ($device['revoked_at'] === null): ?>
<form method="post" action="/devices/revoke">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <input type="hidden" name="action" value="device">
    <input type="hidden" name="deviceId" value="<?= (int) $device['id'] ?>">
    <button type="submit">Revoke this device and all its credentials</button>
</form>
<?php endif; ?>
<?php endforeach; ?>
<h3>Project credentials</h3>
<?php foreach ($data['credentials'] as $credential): ?>
<p>Credential <?= (int) $credential['id'] ?>, device <?= (int) $credential['device_id'] ?>, project <?= TemplateRenderer::escape($credential['project_name']) ?> (<?= (int) $credential['project_id'] ?>).
Lifetime: <?= (int) $credential['lifetime_days'] ?> days.
<?= $credential['expires_at'] === null ? 'No time expiration.' : 'Expires: ' . TemplateRenderer::escape((new DateTimeImmutable($credential['expires_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s T')) . '.' ?>
<?= $credential['consumed_allocation_id'] === null ? '' : 'Single allocation consumed; original request replay only.' ?>
<?= $credential['revoked_at'] === null ? '' : 'Revoked.' ?></p>
<?php if ($credential['revoked_at'] === null): ?>
<form method="post" action="/devices/revoke">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <input type="hidden" name="action" value="credential">
    <input type="hidden" name="credentialId" value="<?= (int) $credential['id'] ?>">
    <button type="submit">Revoke this credential</button>
</form>
<?php endif; ?>
<?php endforeach; ?>
