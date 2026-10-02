<?php

/** Shows a project, optional repository link and multiple named tokens. */
declare(strict_types=1);

use Ordinal\View\TemplateRenderer;

$project = $data['project'];
$projectId = (int) $project['id'];
$csrfToken = TemplateRenderer::escape($data['session']->csrfToken);
?>
<h2><?= TemplateRenderer::escape($project['name']) ?></h2>
<?php if ($project['repository_url'] !== null): ?><p><a href="<?= TemplateRenderer::escape($project['repository_url']) ?>" rel="noopener noreferrer">Repository</a></p><?php endif; ?>
<p>Next build number: <?= (int) $project['next_build_number'] ?><?= $project['is_exhausted'] ? ' (exhausted)' : '' ?>.</p>
<p><a href="/projects/<?= $projectId ?>/counter">Counter</a> · <a href="/projects/<?= $projectId ?>/history">Build history</a></p>
<?php if ($project['archived_at'] === null): ?>
<h3>Project tokens</h3>
<p>Generate a token for each build system or person. Every token belongs only to this project.</p>
<?php if ($data['tokens'] === []): ?><p>No tokens yet.</p><?php endif; ?>
<?php foreach ($data['tokens'] as $token): ?>
<section class="project-token">
    <h4><?= TemplateRenderer::escape($token['name']) ?></h4>
    <p><?= $token['revoked_at'] === null ? 'Active' : 'Revoked' ?> · <?= $token['expires_at'] === null ? 'No expiration' : 'Expires: ' . TemplateRenderer::escape($token['expires_at']) ?></p>
    <?php if ($token['revoked_at'] === null): ?>
    <details><summary>Manage token</summary>
    <form method="post" action="/projects/<?= $projectId ?>/tokens">
        <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>"><input type="hidden" name="tokenId" value="<?= (int) $token['id'] ?>"><input type="hidden" name="action" value="rename">
        <label>Token name <input name="name" value="<?= TemplateRenderer::escape($token['name']) ?>" required></label>
        <button type="submit">Rename token</button>
    </form>
    <form method="post" action="/projects/<?= $projectId ?>/tokens">
        <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>"><input type="hidden" name="tokenId" value="<?= (int) $token['id'] ?>"><input type="hidden" name="action" value="rotate">
        <label>New expiration in days (optional) <input name="lifetimeDays" inputmode="numeric" placeholder="No expiration"></label>
        <p>Replacing the secret immediately invalidates the previous one. Retry history stays.</p>
        <button type="submit">Replace secret</button>
    </form>
    <form method="post" action="/projects/<?= $projectId ?>/tokens">
        <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>"><input type="hidden" name="tokenId" value="<?= (int) $token['id'] ?>"><input type="hidden" name="action" value="revoke">
        <button type="submit">Revoke token</button>
    </form>
    </details>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<h3>Generate token</h3>
<form method="post" action="/projects/<?= $projectId ?>/tokens">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>"><input type="hidden" name="action" value="create">
    <p><label>Token name <input name="name" placeholder="Laptop or CI" required></label></p>
    <p><label>Expires after days (optional) <input name="lifetimeDays" inputmode="numeric" placeholder="No expiration"></label></p>
    <button type="submit">Generate token</button>
</form>
<h3>Project settings</h3>
<form method="post" action="/projects/<?= $projectId ?>">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>">
    <p><label>Project name <input name="name" value="<?= TemplateRenderer::escape($project['name']) ?>" required></label></p>
    <p><label>Repository URL (optional) <input type="url" name="repositoryUrl" value="<?= TemplateRenderer::escape($project['repository_url'] ?? '') ?>"></label></p>
    <button type="submit">Save project</button>
</form>
<?php else: ?><p>This project is archived. Tokens cannot allocate numbers until it is restored.</p><?php endif; ?>
<form method="post" action="/projects/<?= $projectId ?>/archive">
    <input type="hidden" name="csrfToken" value="<?= $csrfToken ?>"><input type="hidden" name="isArchived" value="<?= $project['archived_at'] === null ? '1' : '0' ?>">
    <button type="submit"><?= $project['archived_at'] === null ? 'Archive project' : 'Restore project' ?></button>
</form>
