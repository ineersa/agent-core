<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Layout;

use Ineersa\Tui\Layout\TuiSlotRegistry;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;
use Ineersa\Tui\Widget\WidgetPlacementEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TuiSlotRegistry::class)]
final class TuiSlotRegistryPriorityTest extends TestCase
{
    private TuiSlotRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new TuiSlotRegistry();
    }

    public function testWidgetsSortedByOrderAscending(): void
    {
        $low = $this->createLineWidget('LOW');
        $high = $this->createLineWidget('HIGH');

        $this->registry->setWidget('high', $high, WidgetPlacementEnum::AboveEditor, TuiSlotRegistry::ORDER_PINNED_LAST);
        $this->registry->setWidget('low', $low, WidgetPlacementEnum::AboveEditor, 0);

        $widgets = $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::AboveEditor);
        $this->assertSame($low, $widgets[0]);
        $this->assertSame($high, $widgets[1]);
    }

    public function testEqualOrderPreservesInsertionOrder(): void
    {
        $a = $this->createLineWidget('A');
        $b = $this->createLineWidget('B');
        $c = $this->createLineWidget('C');

        $this->registry->setWidget('a', $a, WidgetPlacementEnum::AboveEditor, 0);
        $this->registry->setWidget('b', $b, WidgetPlacementEnum::AboveEditor, 0);
        $this->registry->setWidget('c', $c, WidgetPlacementEnum::AboveEditor, 0);

        $widgets = $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::AboveEditor);
        $this->assertSame([$a, $b, $c], $widgets);
    }

    public function testResettingKeyUpdatesOrder(): void
    {
        $a = $this->createLineWidget('A');
        $b = $this->createLineWidget('B');

        $this->registry->setWidget('a', $a, WidgetPlacementEnum::AboveEditor, 0);
        $this->registry->setWidget('b', $b, WidgetPlacementEnum::AboveEditor, 5);
        $this->registry->setWidget('a', $a, WidgetPlacementEnum::AboveEditor, 10);

        $widgets = $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::AboveEditor);
        $this->assertSame($b, $widgets[0]);
        $this->assertSame($a, $widgets[1]);
    }

    public function testBelowEditorOrderSymmetric(): void
    {
        $first = $this->createLineWidget('FIRST');
        $second = $this->createLineWidget('SECOND');

        $this->registry->setWidget('second', $second, WidgetPlacementEnum::BelowEditor, 2);
        $this->registry->setWidget('first', $first, WidgetPlacementEnum::BelowEditor, 1);

        $widgets = $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::BelowEditor);
        $this->assertSame([$first, $second], $widgets);
    }

    private function createLineWidget(string $line): TuiWidget
    {
        return new class($line) implements TuiWidget {
            public function __construct(private readonly string $line)
            {
            }

            public function render(TuiRenderContext $context): array
            {
                return [$this->line];
            }
        };
    }
}
