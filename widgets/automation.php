<?php

/** Shows stable token metadata and protected project administration forms without stored secrets. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;

$csrfToken = TemplateRenderer::escape($data['session']->csrfToken);
?>
<h2>Automation tokens for project <?= (int) $data['projectId'] ?></h2>
<p><a href="/projects/<?= (int) $data['projectId'] ?>">Project</a> · <a href="/account">Account</a></p>
<p>These credentials allow allocation and its replay only. Store the secret in your CI secret store. It is displayed once. Rotation preserves the token ID and request history, replaces the secret, and uses current expiry policy.</p>
<p>Current default lifetime: <?= (int) $data['policy']['automation_lifetime_days'] ?> days. No-expiration tokens: <?= $data['policy']['is_automation_without_expiration_allowed'] ? 'allowed' : 'disabled' ?>. Existing tokens retain their assigned expiry until rotation.</p>
<form method="post" action="/automation">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <input type="hidden" name="projectId" value="<?= (int) $data['projectId'] ?>">
    <input type="hidden" name="action" value="create">
    <p><label>Token name <input name="name" maxlength="200" required></label></p>
    <p><label>Expiration <select name="hasNoExpiration"><option value="0">Current default</option><?php if ($data['policy']['is_automation_without_expiration_allowed']): ?><option value="1">No expiration</option><?php endif; ?></select></label></p>
    <button type="submit">Create automation token</button>
</form>
<?php foreach ($data['tokens'] as $token): ?>
<h3>Token <?= (int) $token['id'] ?>: <?= TemplateRenderer::escape($token['name']) ?></h3>
<p><?= $token['revoked_at'] === null ? 'Active unless expired.' : 'Revoked.' ?> <?= $token['expires_at'] === null ? 'No time expiration.' : 'Expires: ' . TemplateRenderer::escape((new DateTimeImmutable($token['expires_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s T')) . '.' ?></p>
<form method="post" action="/automation">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <input type="hidden" name="projectId" value="<?= (int) $data['projectId'] ?>">
    <input type="hidden" name="tokenId" value="<?= (int) $token['id'] ?>">
    <input type="hidden" name="action" value="rename">
    <label>Token name <input name="name" value="<?= TemplateRenderer::escape($token['name']) ?>" maxlength="200" required></label>
    <button type="submit">Save token name</button>
</form>
<?php if ($token['revoked_at'] === null): ?>
<form method="post" action="/automation">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <input type="hidden" name="projectId" value="<?= (int) $data['projectId'] ?>">
    <input type="hidden" name="tokenId" value="<?= (int) $token['id'] ?>">
    <input type="hidden" name="action" value="rotate">
    <label>New expiration <select name="hasNoExpiration"><option value="0">Current default</option><?php if ($data['policy']['is_automation_without_expiration_allowed']): ?><option value="1">No expiration</option><?php endif; ?></select></label>
    <button type="submit">Rotate secret (invalidates the old secret)</button>
</form>
<form method="post" action="/automation">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <input type="hidden" name="projectId" value="<?= (int) $data['projectId'] ?>">
    <input type="hidden" name="tokenId" value="<?= (int) $token['id'] ?>">
    <input type="hidden" name="action" value="revoke">
    <button type="submit">Revoke this token</button>
</form>
<?php endif; ?>
<?php endforeach; ?>
