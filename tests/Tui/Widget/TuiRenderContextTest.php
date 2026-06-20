<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Widget;

use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Widget\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TuiRenderContext::class)]
final class TuiRenderContextTest extends TestCase
{
    public function testDefaultDimensions(): void
    {
        $context = new TuiRenderContext();

        self::assertSame(80, $context->terminalWidth);
        self::assertSame(24, $context->terminalHeight);
    }

    public function testCustomDimensions(): void
    {
        $context = new TuiRenderContext(terminalWidth: 100, terminalHeight: 40);

        self::assertSame(100, $context->terminalWidth);
        self::assertSame(40, $context->terminalHeight);
    }

    public function testWithWidth(): void
    {
        $theme = $this->createTheme();
        $context = new TuiRenderContext(terminalWidth: 80, terminalHeight: 24, theme: $theme);
        $modified = $context->withWidth(120);

        self::assertSame(120, $modified->terminalWidth);
        self::assertSame(24, $modified->terminalHeight);
        // Original unchanged
        self::assertSame(80, $context->terminalWidth);
    }

    public function testWithHeight(): void
    {
        $theme = $this->createTheme();
        $context = new TuiRenderContext(terminalWidth: 80, terminalHeight: 24, theme: $theme);
        $modified = $context->withHeight(50);

        self::assertSame(80, $modified->terminalWidth);
        self::assertSame(50, $modified->terminalHeight);
    }

    public function testHasTheme(): void
    {
        $theme = $this->createTheme();
        $context = new TuiRenderContext(theme: $theme);

        self::assertSame('test', $context->theme->name());
    }

    public function testWithTheme(): void
    {
        $original = $this->createTheme();
        $newTheme = new DefaultTheme(new ThemePalette('other', []));
        $context = new TuiRenderContext(theme: $original);
        $modified = $context->withTheme($newTheme);

        self::assertSame('test', $context->theme->name());
        self::assertSame('other', $modified->theme->name());
    }

    private function createTheme(): DefaultTheme
    {
        return new DefaultTheme(new ThemePalette('test', ['accent' => 'cyan', 'muted' => '#888', 'error' => 'red']));
    }
}
