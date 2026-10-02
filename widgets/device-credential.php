<?php

/** Shows a newly issued credential only in its uncached HTTPS creation response. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<h2>Project credential approved</h2>
<p>Device ID: <?= (int) $data['deviceId'] ?>. Credential ID: <?= (int) $data['credentialId'] ?>. Project ID: <?= (int) $data['projectId'] ?>.</p>
<p>Copy this secret now into your local credential storage. It cannot be shown again. Send it over HTTPS in the Authorization: Bearer header.</p>
<?php require __DIR__ . '/secret.php'; ?>
<p>Lifetime: <?= (int) $data['lifetimeDays'] ?> days. <?= $data['expiresAt'] === null ? 'No time expiration.' : 'Expires: ' . TemplateRenderer::escape((new DateTimeImmutable($data['expiresAt']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s T')) . '.' ?></p>
<?php if ($data['lifetimeDays'] === 0): ?><p>This credential can allocate once, then retrieve only that original request. Sign in through your provider again for another allocation.</p><?php endif; ?>
<p><a href="/devices">Devices</a> · <a href="/projects/<?= (int) $data['projectId'] ?>">Project</a></p>
