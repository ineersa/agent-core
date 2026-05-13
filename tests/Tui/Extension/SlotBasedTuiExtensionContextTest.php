<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Extension;

use Ineersa\Tui\Extension\SlotBasedTuiExtensionContext;
use Ineersa\Tui\Layout\TuiSlotRegistry;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;
use Ineersa\Tui\Widget\WidgetPlacement;
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
        self::assertSame($widget, $this->registry->getHeader());

        $this->context->setHeader(null);
        self::assertNull($this->registry->getHeader());
    }

    public function testSetFooter(): void
    {
        $widget = $this->createDummyWidget();
        $this->context->setFooter($widget);
        self::assertSame($widget, $this->registry->getFooter());
    }

    public function testSetEditorComponent(): void
    {
        $widget = $this->createDummyWidget();
        $this->context->setEditorComponent($widget);
        self::assertSame($widget, $this->registry->getEditorComponent());
    }

    public function testSetWidget(): void
    {
        $widget = $this->createDummyWidget();
        $this->context->setWidget('test', $widget, WidgetPlacement::AboveEditor);

        $widgets = $this->registry->getWidgetsByPlacement(WidgetPlacement::AboveEditor);
        self::assertCount(1, $widgets);
        self::assertSame($widget, $widgets[0]);

        // Remove by setting null
        $this->context->setWidget('test', null);
        self::assertCount(0, $this->registry->getWidgetsByPlacement(WidgetPlacement::AboveEditor));
    }

    public function testSetStatus(): void
    {
        $this->context->setStatus('key', 'value');
        self::assertSame(['key' => 'value'], $this->registry->getStatusEntries());

        $this->context->setStatus('key', null);
        self::assertSame([], $this->registry->getStatusEntries());
    }

    public function testSetWorkingMessage(): void
    {
        $this->context->setWorkingMessage('Busy');
        self::assertSame('Busy', $this->registry->getWorkingMessage());

        $this->context->setWorkingMessage(null);
        self::assertSame('', $this->registry->getWorkingMessage());
    }

    public function testSetWorkingVisible(): void
    {
        $this->context->setWorkingVisible(false);
        self::assertFalse($this->registry->isWorkingVisible());

        $this->context->setWorkingVisible(true);
        self::assertTrue($this->registry->isWorkingVisible());
    }

    public function testOnTerminalInput(): void
    {
        $handler = function (string $data): void {};
        $this->context->onTerminalInput($handler);

        $handlers = $this->registry->getInputHandlers();
        self::assertCount(1, $handlers);
        self::assertSame($handler, $handlers[0]);
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
