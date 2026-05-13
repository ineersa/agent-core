<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Theme;

use Ineersa\Tui\Theme\ThemeColor;
use Ineersa\Tui\Theme\ThemeLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThemeLoader::class)]
final class ThemeLoaderTest extends TestCase
{
    public function testLoadBuiltinCyberpunkTheme(): void
    {
        $loader = new ThemeLoader();
        $path = __DIR__.'/../../../config/themes/cyberpunk.yaml';

        $palette = $loader->loadFile($path);

        self::assertSame('cyberpunk', $palette->name);
        // electric var resolves to #00ffff
        self::assertSame('#00ffff', $palette->get(ThemeColor::Accent));
        // hot resolves to #ff3366
        self::assertSame('#ff3366', $palette->get(ThemeColor::Error));
        // smoke resolves to #718096
        self::assertSame('#718096', $palette->get(ThemeColor::Muted));
    }

    public function testLoadDirectoryFindsThemes(): void
    {
        $loader = new ThemeLoader();
        $dir = __DIR__.'/../../../config/themes';

        $palettes = $loader->loadDirectory($dir);

        self::assertGreaterThanOrEqual(3, \count($palettes));

        $names = array_map(fn ($p) => $p->name, $palettes);
        self::assertContains('cyberpunk', $names);
        self::assertContains('nord', $names);
    }

    public function testLoadEmptyDirectory(): void
    {
        $loader = new ThemeLoader();
        $palettes = $loader->loadDirectory(__DIR__.'/nonexistent_dir');

        self::assertSame([], $palettes);
    }
}
