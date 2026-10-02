<?php

/** Displays accessible projects and the protected immutable repository-link form. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<h2>Projects</h2>
<nav><a href="/account">Account</a><?php if ($data['isAdministrator']): ?> · <a href="/administration">Instance administration</a><?php endif; ?></nav>
<ul>
<?php foreach ($data['projects'] as $project): ?>
    <li><a href="/projects/<?= (int) $project['id'] ?>"><?= TemplateRenderer::escape($project['name']) ?></a><?= $project['archived_at'] === null ? '' : ' (archived)' ?></li>
<?php endforeach; ?>
</ul>
<?php if ($data['isAdministrator']): ?>
<h3>Link a repository</h3>
<p>Sign in through the repository's provider connection before linking it. The repository must be accessible to that provider authorization.</p>
<form method="post" action="/projects">
    <input type="hidden" name="csrfToken" value="<?= TemplateRenderer::escape($data['session']->csrfToken) ?>">
    <input type="hidden" name="connectionId" value="<?= $data['session']->providerConnectionId ?>">
    <p><label>Immutable provider repository ID <input name="repositoryId" inputmode="numeric" required></label></p>
    <p><label>Project name <input name="name" required></label></p>
    <button type="submit">Create project</button>
</form>
<?php endif; ?>
