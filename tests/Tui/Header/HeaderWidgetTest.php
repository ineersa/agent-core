<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Header;

use Ineersa\Tui\Header\HeaderWidget;
use Ineersa\Tui\Widget\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderWidget::class)]
final class HeaderWidgetTest extends TestCase
{
    public function testDefaultLogoRendersMultipleLines(): void
    {
        $widget = new HeaderWidget();
        $lines = $widget->render(new TuiRenderContext());

        self::assertGreaterThan(1, count($lines), 'Logo should render multiple lines');
        self::assertStringContainsString('█', $lines[0], 'Logo first line should contain box drawing chars');
    }

    public function testCustomTextRenders(): void
    {
        $widget = new HeaderWidget('My Custom App');
        $lines = $widget->render(new TuiRenderContext());

        self::assertCount(1, $lines);
        self::assertStringContainsString('My Custom App', $lines[0]);
    }

    public function testLogoContentContainsBoxDrawingChars(): void
    {
        $widget = new HeaderWidget();
        $allText = implode("\n", $widget->render(new TuiRenderContext()));

        // The logo uses Unicode box-drawing characters to spell HATFIELD
        self::assertStringContainsString('█', $allText);
        self::assertStringContainsString('╗', $allText);
        self::assertStringContainsString('╚', $allText);
        self::assertStringContainsString('╔', $allText);
        self::assertStringContainsString('═', $allText);
    }
}
