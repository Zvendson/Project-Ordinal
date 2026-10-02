<?php

/** Shows a linked project only after current contributor or administrator access is verified. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<h2><?= TemplateRenderer::escape($data['project']['name']) ?></h2>
<p>Provider connection: <?= (int) $data['project']['provider_connection_id'] ?>. Repository ID: <?= TemplateRenderer::escape($data['project']['provider_repository_id']) ?>.</p>
<p>Current access: <?= $data['permissions']->canAdministerProject ? 'project administration' : 'contributor' ?>.</p>
<p><a href="/projects">Projects</a> · <a href="/account">Account</a></p>
