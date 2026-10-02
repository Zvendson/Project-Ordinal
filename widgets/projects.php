<?php

/** Lists independent projects and a name/optional URL creation form. */
declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<h2>Projects</h2>
<?php if ($data['projects'] === []): ?><p>No projects yet. Create your first one below.</p><?php endif; ?>
<ul>
<?php foreach ($data['projects'] as $project): ?>
    <li><a href="/projects/<?= (int) $project['id'] ?>"><?= TemplateRenderer::escape($project['name']) ?></a><?= $project['archived_at'] === null ? '' : ' (archived)' ?></li>
<?php endforeach; ?>
</ul>
<h3>Create project</h3>
<form method="post" action="/projects">
    <input type="hidden" name="csrfToken" value="<?= TemplateRenderer::escape($data['session']->csrfToken) ?>">
    <p><label>Project name <input name="name" required></label></p>
    <p><label>Repository URL (optional) <input type="url" name="repositoryUrl" placeholder="https://github.com/owner/repository"></label></p>
    <p>You can leave the URL empty. It is just a link for your reference.</p>
    <button type="submit">Create project</button>
</form>
