<?php

declare(strict_types=1);

namespace Ordinal\Build;

use RuntimeException;

/** Combines shared and widget styles into a deterministic static release asset. */
final class AssetBuilder
{
    /** Keeps temporary release filenames separate even when two builds run together. */
    private const int TEMPORARY_NAME_BYTES = 8;
    /**
     * Validates every source before replacing the previous release stylesheet atomically.
     *
     * @param string $sourceDirectory
     * @param string $targetPath
     * @return void
     * @throws RuntimeException
     */
    public function buildStyles(string $sourceDirectory, string $targetPath): void
    {
        $paths = glob($sourceDirectory . '/widgets/*.css');
        if ($paths === false || $paths === []) { throw new RuntimeException('Widget styles are missing.'); }
        sort($paths, SORT_STRING);
        array_unshift($paths, $sourceDirectory . '/shared.css');
        $output = '';
        foreach ($paths as $path) {
            $content = is_file($path) ? file_get_contents($path) : false;
            if ($content === false || trim($content) === '') { throw new RuntimeException('A stylesheet source is missing or empty.'); }
            $output .= trim($content) . "\n";
        }
        $temporaryPath = $targetPath . '.' . bin2hex(random_bytes(self::TEMPORARY_NAME_BYTES)) . '.tmp';
        try {
            if (file_put_contents($temporaryPath, $output) !== strlen($output) || !rename($temporaryPath, $targetPath)) {
                throw new RuntimeException('Release stylesheet could not be written.');
            }
        } finally {
            if (is_file($temporaryPath)) { unlink($temporaryPath); }
        }
    }
}
