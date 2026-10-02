<?php

/** Displays configured provider choices without client secrets. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<h2>Sign in</h2>
<p>Your account belongs to the provider you choose. Accounts from different servers stay separate.</p>
<ul>
<?php foreach ($data['connections'] as $connection): ?>
    <li><a href="/login/start?connectionId=<?= (int) $connection['id'] ?>"><?= TemplateRenderer::escape($connection['name']) ?></a></li>
<?php endforeach; ?>
</ul>
<?php if ($data['connections'] === []): ?>
<p>No provider connections are available. Contact the server operator.</p>
<?php endif; ?>
<p><a href="/">Home</a></p>
