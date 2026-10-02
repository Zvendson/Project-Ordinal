<?php

/** Shows bounded build/audit history with named token attribution and UTC timestamps. */
declare(strict_types=1);

use Ordinal\Repository\AuditRepository;
use Ordinal\View\TemplateRenderer;

$isHistory = $data['projectId'] !== null;
$pagePath = $isHistory ? '/projects/' . (int) $data['projectId'] . '/history' : '/logs';
?>
<h2><?= $isHistory ? 'Build history' : 'Audit logs' ?></h2>
<?php if ($data['events'] === []): ?><p>No events yet.</p><?php else: ?>
<div class="table-scroll" role="region" aria-label="History table" tabindex="0">
<table>
    <caption>Newest events first. All times UTC.</caption>
    <thead><tr><th>Time (UTC)</th><th>Project</th><th>Token</th><th>Action</th><th>Outcome</th><th>Build number</th><th>Request ID</th></tr></thead>
    <tbody>
    <?php foreach ($data['events'] as $event): ?>
        <tr>
            <td><?= TemplateRenderer::escape($event['created_at_utc']) ?></td>
            <td><?= TemplateRenderer::escape($event['project_name'] ?? 'Instance') ?></td>
            <td><?= TemplateRenderer::escape($event['automation_name'] ?? ($event['action'] === 'allocate_build_number' ? 'Unverified' : 'Administrator')) ?></td>
            <td><?= TemplateRenderer::escape($event['action']) ?></td><td><?= TemplateRenderer::escape($event['outcome']) ?></td>
            <td><?= $event['build_number'] === null ? '—' : (int) $event['build_number'] ?></td>
            <td><?= TemplateRenderer::escape($event['request_id'] ?? '—') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if (count($data['events']) === AuditRepository::PAGE_SIZE): ?><p><a href="<?= $pagePath ?>?beforeId=<?= (int) $data['events'][array_key_last($data['events'])]['id'] ?>">Older events</a></p><?php endif; ?>
<?php endif; ?>
