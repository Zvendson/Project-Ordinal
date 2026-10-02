<?php

declare(strict_types=1);

/** Builds the static release stylesheet after TypeScript compilation, without production Node dependencies. */
require dirname(__DIR__) . '/vendor/autoload.php';

(new Ordinal\Build\AssetBuilder())->buildStyles(dirname(__DIR__) . '/assets/css', dirname(__DIR__) . '/public/assets/app.css');
echo "Release stylesheet built.\n";
