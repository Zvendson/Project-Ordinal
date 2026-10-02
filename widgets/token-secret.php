<?php

/** Shows a generated project token once with copy support and the allocation endpoint. */
declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<h2>Token: <?= TemplateRenderer::escape($data['name']) ?></h2>
<p>Copy and store this token now. Its secret cannot be displayed again.</p>
<?php require __DIR__ . '/secret.php'; ?>
<p>Build endpoint: <code>/api/projects/<?= (int) $data['projectId'] ?>/build-numbers</code></p>
<p>Send the token as <code>Authorization: Bearer YOUR_TOKEN</code> and a JSON body containing a stable UUID <code>requestId</code>. Keep the same request ID when retrying a build attempt.</p>
<p><a href="/projects/<?= (int) $data['projectId'] ?>">Back to project</a></p>
