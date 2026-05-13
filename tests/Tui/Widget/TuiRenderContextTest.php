<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Widget;

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
        $context = new TuiRenderContext(terminalWidth: 80, terminalHeight: 24);
        $modified = $context->withWidth(120);

        self::assertSame(120, $modified->terminalWidth);
        self::assertSame(24, $modified->terminalHeight);
        // Original unchanged
        self::assertSame(80, $context->terminalWidth);
    }

    public function testWithHeight(): void
    {
        $context = new TuiRenderContext(terminalWidth: 80, terminalHeight: 24);
        $modified = $context->withHeight(50);

        self::assertSame(80, $modified->terminalWidth);
        self::assertSame(50, $modified->terminalHeight);
    }
}
