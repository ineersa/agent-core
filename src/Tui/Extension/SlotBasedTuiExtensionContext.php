<?php

declare(strict_types=1);

namespace Ineersa\Tui\Extension;

use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Layout\InputPriority;
use Ineersa\Tui\Layout\TuiSlotRegistry;

/**
 * Concrete implementation of TuiExtensionContext wired to the session's
 * slot registry and ChatScreen-provided closures.
 *
 * Status/working mutations are routed through ChatScreen-provided closures
 * so the native widgets stay in sync; the registry is used only for
 * terminal input handler registration (input handlers remain registry-owned).
 */
final class SlotBasedTuiExtensionContext implements TuiExtensionContext
{
    /**
     * @param \Closure(string, ?string): void $onStatus         ChatScreen status sync (owns widget paint)
     * @param \Closure(?string): void         $onWorkingMessage ChatScreen working-message sync (owns widget paint)
     * @param \Closure(bool): void            $onWorkingVisible ChatScreen working-visibility sync (owns widget paint)
     */
    public function __construct(
        private readonly TuiSlotRegistry $registry,
        private readonly FooterDataProvider $footerDataProvider,
        private readonly \Closure $onStatus,
        private readonly \Closure $onWorkingMessage,
        private readonly \Closure $onWorkingVisible,
    ) {
    }

    public function setStatus(string $key, ?string $text): void
    {
        ($this->onStatus)($key, $text);
    }

    public function setWorkingMessage(?string $message): void
    {
        ($this->onWorkingMessage)($message);
    }

    public function setWorkingVisible(bool $visible): void
    {
        ($this->onWorkingVisible)($visible);
    }

    public function setFooterProvider(string $key, ?FooterSegmentProvider $provider): void
    {
        $this->footerDataProvider->setProvider($key, $provider);
    }

    /**
     * Register a native terminal input listener.
     *
     * @param callable $handler Symfony TUI InputEvent listener (may stop propagation)
     */
    public function onTerminalInput(callable $handler, int $priority = InputPriority::EXTENSION_DEFAULT): void
    {
        $this->registry->addInputHandler($handler, $priority);
    }
}
