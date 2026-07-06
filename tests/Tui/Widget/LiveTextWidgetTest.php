<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Widget;

use Ineersa\Tui\Widget\LiveTextWidget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;

final class LiveTextWidgetTest extends TestCase
{
    #[Test]
    public function expandedWidgetClipsToLastRowsForScrollToBottom(): void
    {
        $lines = [];
        for ($i = 1; $i <= 50; ++$i) {
            $lines[] = 'line-'.$i;
        }
        $widget = new LiveTextWidget(static fn (): string => implode("\n", $lines));
        $widget->expandVertically(true);

        $rendered = $widget->render(new RenderContext(columns: 40, rows: 10));

        $this->assertCount(10, $rendered);
        $this->assertSame('line-41', $rendered[0]);
        $this->assertSame('line-50', $rendered[9]);
    }

    #[Test]
    public function nonExpandedWidgetReturnsAllWrappedLines(): void
    {
        $widget = new LiveTextWidget(static fn (): string => "a\nb\nc");
        $rendered = $widget->render(new RenderContext(columns: 40, rows: 2));

        $this->assertSame(['a', 'b', 'c'], $rendered);
    }
}
