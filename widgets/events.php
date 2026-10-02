<?php

/** Shows bounded, escaped build history or administrative audit rows with explicit UTC timestamps. */

declare(strict_types=1);

use Ordinal\Repository\AuditRepository;
use Ordinal\View\TemplateRenderer;
?>
<h2><?= $data['isHistory'] ? 'Build history' : 'Audit logs' ?><?= $data['project'] === null ? '' : ': ' . TemplateRenderer::escape($data['project']['name']) ?></h2>
<p>Times are shown in UTC. Events are listed newest first, up to <?= AuditRepository::PAGE_SIZE ?> per page.</p>
<?php if ($data['events'] === []): ?><p>No visible events.</p><?php else: ?>
<table>
    <thead><tr><th>Time (UTC)</th><th>Project</th><th>Caller</th><th>Action</th><th>Outcome</th><th>Build number</th><th>Request ID</th><?php if (!$data['isHistory']): ?><th>Details</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($data['events'] as $event): ?>
        <tr>
            <td><?= TemplateRenderer::escape($event['created_at_utc']) ?> UTC</td>
            <td><?= TemplateRenderer::escape($event['project_name'] ?? 'Instance') ?></td>
            <td><?php if ($event['user_id'] !== null): ?><?= TemplateRenderer::escape($event['display_name']) ?> (connection <?= (int) $event['provider_connection_id'] ?>, <?= TemplateRenderer::escape($event['provider_name']) ?>, <?= TemplateRenderer::escape($event['provider_server_url']) ?>, user <?= TemplateRenderer::escape($event['provider_user_id']) ?>)
                <?php elseif ($event['automation_token_id'] !== null): ?><?= TemplateRenderer::escape($event['automation_name']) ?> (CI token <?= (int) $event['automation_token_id'] ?>)
                <?php elseif ($event['caller_kind'] === 'anonymous'): ?>Anonymous
                <?php else: ?>Unverified caller<?php endif; ?></td>
            <td><?= TemplateRenderer::escape($event['action']) ?></td>
            <td><?= TemplateRenderer::escape($event['outcome']) ?></td>
            <td><?= $event['build_number'] === null ? '—' : (int) $event['build_number'] ?></td>
            <td><?= TemplateRenderer::escape($event['request_id'] ?? '—') ?></td>
            <?php if (!$data['isHistory']): ?><td><?= TemplateRenderer::escape($event['details']) ?></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if (count($data['events']) === AuditRepository::PAGE_SIZE): ?><p><a href="<?= TemplateRenderer::escape($data['pagePath']) ?>?beforeId=<?= (int) $data['events'][array_key_last($data['events'])]['id'] ?>">Older events</a></p><?php endif; ?>
<?php endif; ?>
<?php if ($data['project'] === null): ?><p><a href="/logs/cleanup">Delete old logs</a> · <a href="/administration">Administration</a></p>
<?php else: ?><p><a href="/projects/<?= (int) $data['project']['id'] ?>">Back to project</a></p><?php endif; ?>
