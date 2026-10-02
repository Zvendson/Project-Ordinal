<?php

/** Shows an escaped secret with manual selection and an optional explicit clipboard action. */
declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<section class="secret" aria-label="Issued secret">
    <label for="issued-secret">Secret (shown once)</label>
    <textarea id="issued-secret" readonly rows="3" spellcheck="false" autocomplete="off"><?= TemplateRenderer::escape($data['token']) ?></textarea>
    <button type="button" data-copy-secret hidden>Copy secret</button>
    <p role="status" data-copy-status></p>
</section>
