<?php

/** Provides the accessible page shell and static assets for fixed private widgets. */

declare(strict_types=1);

use Ordinal\View\TemplateRenderer;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Project: Ordinal</title>
    <link rel="stylesheet" href="/assets/app.css">
    <script type="module" src="/assets/scripts/app.js"></script>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header class="page-header">
        <h1>Project: Ordinal</h1>
        <?php require __DIR__ . '/navigation.php'; ?>
    </header>
    <main id="main-content" tabindex="-1" data-widget="<?= TemplateRenderer::escape(pathinfo($filename, PATHINFO_FILENAME)) ?>">
        <?php require __DIR__ . '/' . $filename; ?>
    </main>
    <footer>Project: Ordinal · Build numbers for your projects</footer>
</body>
</html>
