<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Extension;

use Ineersa\Tui\Extension\SlotBasedTuiExtensionContext;
use Ineersa\Tui\Layout\TuiSlotRegistry;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;
use Ineersa\Tui\Widget\WidgetPlacementEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SlotBasedTuiExtensionContext::class)]
final class SlotBasedTuiExtensionContextTest extends TestCase
{
    private TuiSlotRegistry $registry;
    private SlotBasedTuiExtensionContext $context;

    protected function setUp(): void
    {
        $this->registry = new TuiSlotRegistry();
        $this->context = new SlotBasedTuiExtensionContext($this->registry);
    }

    public function testSetHeader(): void
    {
        $widget = $this->createDummyWidget();
        $this->context->setHeader($widget);
        $this->assertSame($widget, $this->registry->getHeader());

        $this->context->setHeader(null);
        $this->assertNull($this->registry->getHeader());
    }

    public function testSetFooter(): void
    {
        $widget = $this->createDummyWidget();
        $this->context->setFooter($widget);
        $this->assertSame($widget, $this->registry->getFooter());
    }

    public function testSetEditorComponent(): void
    {
        $widget = $this->createDummyWidget();
        $this->context->setEditorComponent($widget);
        $this->assertSame($widget, $this->registry->getEditorComponent());
    }

    public function testSetWidget(): void
    {
        $widget = $this->createDummyWidget();
        $this->context->setWidget('test', $widget, WidgetPlacementEnum::AboveEditor);

        $widgets = $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::AboveEditor);
        $this->assertCount(1, $widgets);
        $this->assertSame($widget, $widgets[0]);

        // Remove by setting null
        $this->context->setWidget('test', null);
        $this->assertCount(0, $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::AboveEditor));
    }

    public function testSetWidgetPassesOrder(): void
    {
        $first = $this->createDummyWidget();
        $second = $this->createDummyWidget();

        $this->context->setWidget('first', $first, WidgetPlacementEnum::AboveEditor, 5);
        $this->context->setWidget('second', $second, WidgetPlacementEnum::AboveEditor, 0);

        $widgets = $this->registry->getWidgetsByPlacement(WidgetPlacementEnum::AboveEditor);
        $this->assertSame([$second, $first], $widgets);
    }

    public function testSetStatus(): void
    {
        $this->context->setStatus('key', 'value');
        $this->assertSame(['key' => 'value'], $this->registry->getStatusEntries());

        $this->context->setStatus('key', null);
        $this->assertSame([], $this->registry->getStatusEntries());
    }

    public function testSetWorkingMessage(): void
    {
        $this->context->setWorkingMessage('Busy');
        $this->assertSame('Busy', $this->registry->getWorkingMessage());

        $this->context->setWorkingMessage(null);
        $this->assertSame('', $this->registry->getWorkingMessage());
    }

    public function testSetWorkingVisible(): void
    {
        $this->context->setWorkingVisible(false);
        $this->assertFalse($this->registry->isWorkingVisible());

        $this->context->setWorkingVisible(true);
        $this->assertTrue($this->registry->isWorkingVisible());
    }

    public function testOnTerminalInput(): void
    {
        $handler = static function (string $data): void {};
        $this->context->onTerminalInput($handler);

        $handlers = $this->registry->getInputHandlers();
        $this->assertCount(1, $handlers);
        $this->assertSame($handler, $handlers[0]);
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
