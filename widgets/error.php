<?php

/** Displays an escaped browser error without exposing internal diagnostics. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<p><?= TemplateRenderer::escape($data['message']) ?></p>
<a href="/">Home</a>
