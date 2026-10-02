<?php

/** Displays an original automation secret only in an uncached HTTPS creation/rotation response. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<h2>Automation secret issued</h2>
<p>Token <?= (int) $data['id'] ?>: <?= TemplateRenderer::escape($data['name']) ?>. Project <?= (int) $data['projectId'] ?>.</p>
<p>Copy this secret into your CI secret store now. It cannot be displayed again. Only trusted jobs should receive it; possession permits allocation for this project.</p>
<pre><?= TemplateRenderer::escape($data['token']) ?></pre>
<p><?= $data['expiresAt'] === null ? 'No time expiration.' : 'Expires: ' . TemplateRenderer::escape((new DateTimeImmutable($data['expiresAt']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s T')) . '.' ?></p>
<p><a href="/automation?projectId=<?= (int) $data['projectId'] ?>">Automation tokens</a></p>
