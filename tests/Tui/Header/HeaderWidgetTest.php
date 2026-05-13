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
    public function testDefaultTitle(): void
    {
        $widget = new HeaderWidget();
        $lines = $widget->render(new TuiRenderContext());

        self::assertCount(1, $lines);
        self::assertStringContainsString('Agent Core', $lines[0]);
    }

    public function testCustomTitle(): void
    {
        $widget = new HeaderWidget('My Custom App');
        $lines = $widget->render(new TuiRenderContext());

        self::assertCount(1, $lines);
        self::assertStringContainsString('My Custom App', $lines[0]);
    }
}
