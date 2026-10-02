<?php

/** Displays an escaped browser error without exposing internal diagnostics. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<h2>Request could not be completed</h2>
<p role="alert"><?= TemplateRenderer::escape($data['message']) ?></p>
<p>For an expired session or credential, sign in again. Your earlier build request ID should be kept for its retry.</p>
<p><a href="/account">Account and reauthentication</a> · <a href="/login">Sign in</a></p>
<a href="/">Home</a>
