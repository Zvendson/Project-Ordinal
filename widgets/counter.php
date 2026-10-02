<?php

/** Shows administrative counter edits, explicit hard-reset confirmation and read-only preview. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<h2>Counter: <?= TemplateRenderer::escape($data['project']['name']) ?></h2>
<p>Next build number: <?= (int) $data['preview']['nextBuildNumber'] ?>. Exhausted: <?= $data['preview']['isExhausted'] ? 'yes' : 'no' ?>.</p>
<p>A preview does not reserve a number. Another build may allocate it before your build starts.</p>
<form method="get" action="/api/projects/<?= (int) $data['project']['id'] ?>/build-numbers/next" data-counter-preview data-project-id="<?= (int) $data['project']['id'] ?>">
    <button type="submit">Check next build number</button>
</form>
<p role="status" data-preview-status></p>
<h3>Edit the next number</h3>
<p>Before the first allocation, any whole number in the range is allowed. Afterward, ordinary edits must increase the next number.</p>
<form method="post" action="/projects/<?= (int) $data['project']['id'] ?>/counter">
    <input type="hidden" name="csrfToken" value="<?= TemplateRenderer::escape($data['session']->csrfToken) ?>">
    <input type="hidden" name="action" value="edit">
    <p><label>Next build number <input name="nextBuildNumber" inputmode="numeric" value="<?= (int) $data['preview']['nextBuildNumber'] ?>" required></label></p>
    <button type="submit">Save counter edit</button>
</form>
<h3>Hard reset</h3>
<p class="warning">Warning: a hard reset can reuse previously allocated numbers. History and original request IDs will remain. Retrying an earlier request still returns its original number.</p>
<p>Sign in through the provider again within five minutes before confirming. <a href="/account">Go to account reauthentication</a>.</p>
<form method="post" action="/projects/<?= (int) $data['project']['id'] ?>/counter">
    <input type="hidden" name="csrfToken" value="<?= TemplateRenderer::escape($data['session']->csrfToken) ?>">
    <input type="hidden" name="action" value="reset">
    <p><label>New next build number <input name="nextBuildNumber" inputmode="numeric" required></label> (0 through 4294967295)</p>
    <p><label>Type the project name <?= TemplateRenderer::escape($data['project']['name']) ?> <input name="projectName" autocomplete="off" required></label></p>
    <input type="hidden" name="hasConfirmedReuse" value="0">
    <p><label><input type="checkbox" name="hasConfirmedReuse" value="1" required> I understand that previously allocated numbers may be reused.</label></p>
    <button type="submit">Confirm hard reset</button>
</form>
<p><a href="/projects/<?= (int) $data['project']['id'] ?>">Back to project</a></p>
