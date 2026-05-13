<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Theme;

use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThemePalette::class)]
final class ThemePaletteTest extends TestCase
{
    public function testName(): void
    {
        $palette = new ThemePalette('test');

        self::assertSame('test', $palette->name);
    }

    public function testGetKnownColor(): void
    {
        $palette = new ThemePalette('test', ['accent' => '#ff00ff']);

        self::assertSame('#ff00ff', $palette->get(\Ineersa\Tui\Theme\ThemeColor::Accent));
    }

    public function testGetMissingColorReturnsEmpty(): void
    {
        $palette = new ThemePalette('test', []);

        self::assertSame('', $palette->get(\Ineersa\Tui\Theme\ThemeColor::Accent));
    }

    public function testHasReturnsTrueForNonEmpty(): void
    {
        $palette = new ThemePalette('test', ['accent' => 'cyan']);

        self::assertTrue($palette->has(\Ineersa\Tui\Theme\ThemeColor::Accent));
    }

    public function testHasReturnsFalseForEmpty(): void
    {
        $palette = new ThemePalette('test', []);

        self::assertFalse($palette->has(\Ineersa\Tui\Theme\ThemeColor::Accent));
    }

    public function testWithOverrides(): void
    {
        $palette = new ThemePalette('test', ['accent' => 'cyan']);

        $overridden = $palette->withOverrides(['accent' => 'magenta', 'muted' => '#888']);

        self::assertSame('test', $overridden->name);
        self::assertSame('magenta', $overridden->get(\Ineersa\Tui\Theme\ThemeColor::Accent));
        self::assertSame('#888', $overridden->get(\Ineersa\Tui\Theme\ThemeColor::Muted));
        // Original unchanged
        self::assertSame('cyan', $palette->get(\Ineersa\Tui\Theme\ThemeColor::Accent));
    }

    public function testFromArray(): void
    {
        $data = [
            'name' => 'cyberpunk',
            'vars' => [
                'neon' => '#ff00ff',
            ],
            'colors' => [
                'accent' => 'neon',
                'text' => '',
                'muted' => '#718096',
            ],
        ];

        $palette = ThemePalette::fromArray($data);

        self::assertSame('cyberpunk', $palette->name);
        // Var 'neon' resolves to #ff00ff
        self::assertSame('#ff00ff', $palette->get(\Ineersa\Tui\Theme\ThemeColor::Accent));
        // Empty stays empty
        self::assertSame('', $palette->get(\Ineersa\Tui\Theme\ThemeColor::Text));
        // Direct hex stays
        self::assertSame('#718096', $palette->get(\Ineersa\Tui\Theme\ThemeColor::Muted));
    }

    public function testFromArrayWithoutVars(): void
    {
        $data = [
            'name' => 'simple',
            'colors' => [
                'accent' => '#abc',
            ],
        ];

        $palette = ThemePalette::fromArray($data);

        self::assertSame('simple', $palette->name);
        self::assertSame('#abc', $palette->get(\Ineersa\Tui\Theme\ThemeColor::Accent));
    }

    public function testFromArrayDefaultName(): void
    {
        $palette = ThemePalette::fromArray(['colors' => []]);

        self::assertSame('unnamed', $palette->name);
    }
}
