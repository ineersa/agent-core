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

    private function createTheme(): DefaultTheme
    {
        return new DefaultTheme(new ThemePalette('test', ['accent' => 'cyan', 'muted' => '#888', 'error' => 'red']));
    }
}
