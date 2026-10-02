<?php

/** Provides shared unstyled page markup for templates selected by the renderer. */

declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Project: Ordinal</title>
</head>
<body>
    <main>
        <h1>Project: Ordinal</h1>
        <?php require __DIR__ . '/' . $filename; ?>
    </main>
</body>
</html>
