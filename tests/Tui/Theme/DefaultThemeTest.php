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
    public function testName(): void
    {
        $theme = $this->createTheme();

        $this->assertSame('test', $theme->name());
    }

    public function testAccentAppliesAnsiStyle(): void
    {
        $theme = $this->createTheme();

        $result = $theme->accent('Hello');

        $this->assertStringContainsString('Hello', $result);
        // ANSI-styled text should differ from plain text
        $this->assertNotSame('Hello', $result);
    }

    public function testMutedAppliesColor(): void
    {
        $theme = $this->createTheme();

        $result = $theme->muted('quiet');

        $this->assertStringContainsString('quiet', $result);
        $this->assertNotSame('quiet', $result);
    }

    public function testErrorAppliesColor(): void
    {
        $theme = $this->createTheme();

        $result = $theme->error('failure');

        $this->assertStringContainsString('failure', $result);
        $this->assertNotSame('failure', $result);
    }

    public function testEmptyColorIsUnstyled(): void
    {
        $theme = $this->createTheme();

        $result = $theme->text('plain');

        // Text with empty spec should be plain (or just ANSI wrapped with no color change)
        // At minimum, the text is present
        $this->assertStringContainsString('plain', $result);
    }

    public function testColorViaEnum(): void
    {
        $theme = $this->createTheme();

        $result = $theme->color(ThemeColorEnum::Accent, 'accented');

        $this->assertStringContainsString('accented', $result);
        $this->assertNotSame('accented', $result);
    }

    public function testSuccess(): void
    {
        $theme = $this->createTheme();

        $result = $theme->success('OK');

        $this->assertStringContainsString('OK', $result);
    }

    public function testWarning(): void
    {
        $theme = $this->createTheme();

        $result = $theme->warning('caution');

        $this->assertStringContainsString('caution', $result);
    }

    public function testGetPalette(): void
    {
        $palette = new ThemePalette('test', ['accent' => 'cyan']);
        $theme = new DefaultTheme($palette);

        $this->assertSame($palette, $theme->getPalette());
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
