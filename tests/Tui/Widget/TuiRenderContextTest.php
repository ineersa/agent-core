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

        $this->assertSame(80, $context->terminalWidth);
        $this->assertSame(24, $context->terminalHeight);
    }

    public function testCustomDimensions(): void
    {
        $context = new TuiRenderContext(terminalWidth: 100, terminalHeight: 40);

        $this->assertSame(100, $context->terminalWidth);
        $this->assertSame(40, $context->terminalHeight);
    }

    public function testWithWidth(): void
    {
        $theme = $this->createTheme();
        $context = new TuiRenderContext(terminalWidth: 80, terminalHeight: 24, theme: $theme);
        $modified = $context->withWidth(120);

        $this->assertSame(120, $modified->terminalWidth);
        $this->assertSame(24, $modified->terminalHeight);
        // Original unchanged
        $this->assertSame(80, $context->terminalWidth);
    }

    public function testWithHeight(): void
    {
        $theme = $this->createTheme();
        $context = new TuiRenderContext(terminalWidth: 80, terminalHeight: 24, theme: $theme);
        $modified = $context->withHeight(50);

        $this->assertSame(80, $modified->terminalWidth);
        $this->assertSame(50, $modified->terminalHeight);
    }

    public function testHasTheme(): void
    {
        $theme = $this->createTheme();
        $context = new TuiRenderContext(theme: $theme);

        $this->assertSame('test', $context->theme->name());
    }

    public function testWithTheme(): void
    {
        $original = $this->createTheme();
        $newTheme = new DefaultTheme(new ThemePalette('other', []));
        $context = new TuiRenderContext(theme: $original);
        $modified = $context->withTheme($newTheme);

        $this->assertSame('test', $context->theme->name());
        $this->assertSame('other', $modified->theme->name());
    }

    private function createTheme(): DefaultTheme
    {
        return new DefaultTheme(new ThemePalette('test', ['accent' => 'cyan', 'muted' => '#888', 'error' => 'red']));
    }
}
