<?php

declare(strict_types=1);

namespace Ordinal\Tests\Unit\Build;

use Ordinal\Build\AssetBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Verifies deterministic release styles and preserves a previous release on missing source. */
final class AssetBuilderTest extends TestCase
{
    /** Builds shared styles before sorted widget modules. @return void */
    public function testBuildsStylesAndPreservesOutputOnMissingSource(): void
    {
        $directory = dirname(__DIR__, 3) . '/.local/assets-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        mkdir($directory . '/widgets');
        try {
            file_put_contents($directory . '/shared.css', 'body { color: black; }');
            file_put_contents($directory . '/widgets/b.css', '.b { margin: 0; }');
            file_put_contents($directory . '/widgets/a.css', '.a { margin: 1rem; }');
            $target = $directory . '/app.css';
            (new AssetBuilder())->buildStyles($directory, $target);
            $built = file_get_contents($target);
            self::assertSame("body { color: black; }\n.a { margin: 1rem; }\n.b { margin: 0; }\n", $built);
            unlink($directory . '/shared.css');
            try { (new AssetBuilder())->buildStyles($directory, $target); self::fail('Missing shared styles were accepted.'); }
            catch (RuntimeException) { self::assertSame($built, file_get_contents($target)); }
        } finally {
            foreach (glob($directory . '/widgets/*') as $file) { unlink($file); }
            foreach (glob($directory . '/*') as $file) { if (is_file($file)) { unlink($file); } }
            rmdir($directory . '/widgets');
            rmdir($directory);
        }
    }
}
