<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Theme;

use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultTheme::class)]
final class DefaultThemeTest extends TestCase
{
    public function testAccentColorAppliesAnsiStyle(): void
    {
        $theme = $this->createTheme();

        $result = $theme->color(ThemeColorEnum::Accent, 'Hello');

        $this->assertStringContainsString('Hello', $result);
        // ANSI-styled text should differ from plain text
        $this->assertNotSame('Hello', $result);
    }

    public function testMissingPaletteKeyFallsBackToUnstyled(): void
    {
        $palette = new ThemePalette('sparse', [ThemeColorEnum::Accent->value => 'cyan']);
        $theme = new DefaultTheme($palette);

        $plain = $theme->color(ThemeColorEnum::ThinkingMax, 'max-level');

        $this->assertSame('max-level', $plain);
    }

    private function createTheme(): DefaultTheme
    {
        return new DefaultTheme(new ThemePalette('test', [
            'accent' => 'cyan',
            'muted' => '#6a6a7a',
            'error' => 'red',
            'text' => '',
        ]));
    }
}
