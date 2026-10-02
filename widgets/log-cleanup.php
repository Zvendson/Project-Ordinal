<?php

/** Previews audit-only deletion before a selected UTC date and requires explicit CSRF-protected confirmation. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<h2>Delete old logs</h2>
<form method="get" action="/logs/cleanup">
    <p><label>Delete entries before this date (UTC) <input type="date" name="beforeDate" value="<?= TemplateRenderer::escape($data['beforeDate']) ?>" required></label></p>
    <button type="submit">Preview deletion</button>
</form>
<p><?= (int) $data['count'] ?> entries are currently older than <?= TemplateRenderer::escape($data['cutoff']) ?>. The actual count may change before confirmation.</p>
<p class="warning">Deletion removes log and build-history entries. It preserves counters, permanent request IDs, allocations and referenced identities. This action cannot be undone from the website.</p>
<form method="post" action="/logs/cleanup">
    <input type="hidden" name="csrfToken" value="<?= TemplateRenderer::escape($data['session']->csrfToken) ?>">
    <input type="hidden" name="beforeDate" value="<?= TemplateRenderer::escape($data['beforeDate']) ?>">
    <input type="hidden" name="isConfirmed" value="0">
    <p><label><input type="checkbox" name="isConfirmed" value="1" required> Delete logs older than <?= TemplateRenderer::escape($data['beforeDate']) ?> at midnight UTC.</label></p>
    <button type="submit">Confirm log deletion</button>
</form>
<p><a href="/logs">Back to logs</a></p>
