<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\Tui\Transcript\PendingMessagesWidget;
use Ineersa\Tui\Widget\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingMessagesWidget::class)]
final class PendingMessagesWidgetTest extends TestCase
{
    public function testEmptyReturnsEmptyLines(): void
    {
        $widget = new PendingMessagesWidget();
        $lines = $widget->render(new TuiRenderContext());

        self::assertSame([], $lines);
    }

    public function testWithMessages(): void
    {
        $widget = new PendingMessagesWidget();
        $widget->addMessage('Compacting...');
        $widget->addMessage('Processing...');

        $lines = $widget->render(new TuiRenderContext());

        self::assertCount(2, $lines);
        self::assertStringContainsString('Compacting', $lines[0]);
        self::assertStringContainsString('Processing', $lines[1]);
    }

    public function testClear(): void
    {
        $widget = new PendingMessagesWidget();
        $widget->addMessage('test');
        $widget->clear();

        self::assertSame([], $widget->render(new TuiRenderContext()));
    }
}
