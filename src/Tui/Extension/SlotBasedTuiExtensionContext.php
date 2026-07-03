<?php

declare(strict_types=1);

namespace Ineersa\Tui\Extension;

use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Layout\TuiSlotRegistry;
use Ineersa\Tui\Widget\TuiWidget;
use Ineersa\Tui\Widget\WidgetPlacementEnum;

/**
 * Concrete implementation of TuiExtensionContext that delegates to a TuiSlotRegistry.
 *
 * Extensions receive an instance wired to the session's slot registry.
 */
final class SlotBasedTuiExtensionContext implements TuiExtensionContext
{
    public function __construct(
        private readonly TuiSlotRegistry $registry,
        private readonly ?FooterDataProvider $footerDataProvider = null,
    ) {
    }

    public function setHeader(?TuiWidget $widget): void
    {
        $this->registry->setHeader($widget);
    }

    public function setFooter(?TuiWidget $widget): void
    {
        $this->registry->setFooter($widget);
    }

    public function setEditorComponent(?TuiWidget $widget): void
    {
        $this->registry->setEditorComponent($widget);
    }

    /**
     * @param int $order @see \Ineersa\Tui\Layout\TuiSlotRegistry::ORDER_DEFAULT
     */
    public function setWidget(string $key, ?TuiWidget $content, WidgetPlacementEnum $placement = WidgetPlacementEnum::AboveEditor, int $order = 0): void
    {
        if (null === $content) {
            $this->registry->removeWidget($key);
        } else {
            $this->registry->setWidget($key, $content, $placement, $order);
        }
    }

    public function setStatus(string $key, ?string $text): void
    {
        $this->registry->setStatus($key, $text);
    }

    public function setWorkingMessage(?string $message): void
    {
        $this->registry->setWorkingMessage($message);
    }

    public function setWorkingVisible(bool $visible): void
    {
        $this->registry->setWorkingVisible($visible);
    }

    public function setFooterProvider(string $key, ?FooterSegmentProvider $provider): void
    {
        $this->footerDataProvider?->setProvider($key, $provider);
    }

    public function onTerminalInput(callable $handler): void
    {
        $this->registry->addInputHandler($handler);
    }
}
