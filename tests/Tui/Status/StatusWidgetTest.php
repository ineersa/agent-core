<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Status;

use Ineersa\Tui\Status\StatusPanelWidget;
use Ineersa\Tui\Status\WorkingStatusWidget;
use Ineersa\Tui\Widget\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkingStatusWidget::class)]
#[CoversClass(StatusPanelWidget::class)]
final class StatusWidgetTest extends TestCase
{
    public function testWorkingStatusIdleByDefault(): void
    {
        $widget = new WorkingStatusWidget();
        $lines = $widget->render(new TuiRenderContext());

        self::assertCount(1, $lines);
        self::assertStringContainsString('idle', $lines[0]);
    }

    public function testWorkingStatusShowsMessage(): void
    {
        $widget = new WorkingStatusWidget();
        $widget->setMessage('Processing request...');

        $lines = $widget->render(new TuiRenderContext());

        self::assertCount(1, $lines);
        self::assertStringContainsString('Processing request', $lines[0]);
    }

    public function testWorkingStatusHidden(): void
    {
        $widget = new WorkingStatusWidget();
        $widget->setVisible(false);

        $lines = $widget->render(new TuiRenderContext());

        self::assertSame([], $lines);
    }

    public function testStatusPanelEmptyByDefault(): void
    {
        $widget = new StatusPanelWidget();
        $lines = $widget->render(new TuiRenderContext());

        self::assertSame([], $lines);
    }

    public function testStatusPanelWithEntries(): void
    {
        $widget = new StatusPanelWidget();
        $widget->setEntry('model', 'claude');
        $widget->setEntry('cwd', '/project');

        $lines = $widget->render(new TuiRenderContext());

        self::assertCount(2, $lines);
        self::assertStringContainsString('model', $lines[0]);
        self::assertStringContainsString('claude', $lines[0]);
    }

    public function testStatusPanelRemoveEntry(): void
    {
        $widget = new StatusPanelWidget();
        $widget->setEntry('key', 'value');
        $widget->setEntry('key', null);

        self::assertSame([], $widget->render(new TuiRenderContext()));
    }
}
