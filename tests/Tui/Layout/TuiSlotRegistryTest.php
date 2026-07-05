<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Layout;

use Ineersa\Tui\Footer\FooterBarWidget;
use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Header\HeaderWidget;
use Ineersa\Tui\Layout\TuiSlotRegistry;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;
use Ineersa\Tui\Widget\WidgetPlacementEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TuiSlotRegistry::class)]
final class TuiSlotRegistryTest extends TestCase
{
    private TuiSlotRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new TuiSlotRegistry();
    }

    public function testDefaultState(): void
    {
        $this->assertNull($this->registry->getHeader());
        $this->assertNull($this->registry->getFooter());
        $this->assertNull($this->registry->getEditorComponent());
        $this->assertSame([], $this->registry->getStatusEntries());
        $this->assertSame([], $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::AboveEditor));
        $this->assertSame([], $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::BelowEditor));
        $this->assertTrue($this->registry->isWorkingVisible());
        $this->assertSame('', $this->registry->getWorkingMessage());
    }

    public function testSetHeader(): void
    {
        $widget = new HeaderWidget();
        $this->registry->setHeader($widget);
        $this->assertSame($widget, $this->registry->getHeader());

        $this->registry->setHeader(null);
        $this->assertNull($this->registry->getHeader());
    }

    public function testSetFooter(): void
    {
        $dataProvider = new FooterDataProvider();
        $widget = new FooterBarWidget($dataProvider);
        $this->registry->setFooter($widget);
        $this->assertSame($widget, $this->registry->getFooter());

        $this->registry->setFooter(null);
        $this->assertNull($this->registry->getFooter());
    }

    public function testSetEditorComponent(): void
    {
        $dummy = $this->createDummyWidget();
        $this->registry->setEditorComponent($dummy);
        $this->assertSame($dummy, $this->registry->getEditorComponent());

        $this->registry->setEditorComponent(null);
        $this->assertNull($this->registry->getEditorComponent());
    }

    public function testSetWidgetAndGetByPlacement(): void
    {
        $widgetAbove = $this->createDummyWidget();
        $widgetBelow = $this->createDummyWidget();

        $this->registry->setWidget('a1', $widgetAbove, WidgetPlacementEnum::AboveEditor);
        $this->registry->setWidget('b1', $widgetBelow, WidgetPlacementEnum::BelowEditor);

        $above = $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::AboveEditor);
        $below = $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::BelowEditor);

        $this->assertCount(1, $above);
        $this->assertCount(1, $below);
        $this->assertSame($widgetAbove, $above[0]);
        $this->assertSame($widgetBelow, $below[0]);
    }

    public function testRemoveWidget(): void
    {
        $widget = $this->createDummyWidget();
        $this->registry->setWidget('test', $widget, WidgetPlacementEnum::AboveEditor);

        $this->assertCount(1, $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::AboveEditor));

        $this->registry->removeWidget('test');

        $this->assertCount(0, $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::AboveEditor));
    }

    public function testMultipleWidgetsInSamePlacementOrder(): void
    {
        $w1 = $this->createDummyWidget();
        $w2 = $this->createDummyWidget();

        $this->registry->setWidget('first', $w1, WidgetPlacementEnum::AboveEditor);
        $this->registry->setWidget('second', $w2, WidgetPlacementEnum::AboveEditor);

        $widgets = $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::AboveEditor);

        $this->assertCount(2, $widgets);
        $this->assertSame($w1, $widgets[0]);
        $this->assertSame($w2, $widgets[1]);
    }

    public function testStatusEntries(): void
    {
        $this->registry->setStatus('key1', 'value1');
        $this->registry->setStatus('key2', 'value2');

        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $this->registry->getStatusEntries());

        $this->registry->setStatus('key1', null);
        $this->assertSame(['key2' => 'value2'], $this->registry->getStatusEntries());
    }

    public function testWorkingState(): void
    {
        $this->registry->setWorkingMessage('Loading...');
        $this->assertSame('Loading...', $this->registry->getWorkingMessage());
        $this->assertTrue($this->registry->isWorkingVisible());

        $this->registry->setWorkingVisible(false);
        $this->assertFalse($this->registry->isWorkingVisible());

        $this->registry->setWorkingMessage(null);
        $this->assertSame('', $this->registry->getWorkingMessage());
    }

    public function testInputHandlers(): void
    {
        $h1 = static function (string $data): void {};
        $h2 = static function (string $data): void {};

        $this->registry->addInputHandler($h1);
        $this->registry->addInputHandler($h2);

        $handlers = $this->registry->getInputHandlers();
        $this->assertCount(2, $handlers);
        $this->assertSame($h1, $handlers[0]);
        $this->assertSame($h2, $handlers[1]);
    }

    private function createDummyWidget(): TuiWidget
    {
        return new class implements TuiWidget {
            public function render(TuiRenderContext $context): array
            {
                return ['dummy'];
            }
        };
    }
}
