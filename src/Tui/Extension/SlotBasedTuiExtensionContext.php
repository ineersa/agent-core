<?php

declare(strict_types=1);

namespace Ineersa\Tui\Extension;

use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Layout\InputPriority;
use Ineersa\Tui\Layout\TuiSlotRegistry;

/**
 * Concrete implementation of TuiExtensionContext that delegates to a TuiSlotRegistry.
 *
 * Extensions receive an instance wired to the session's slot registry.
 * Status/working mutations can be routed through ChatScreen-provided
 * closures so the native widgets stay in sync (same pattern as the
 * working-slot closures).
 */
final class SlotBasedTuiExtensionContext implements TuiExtensionContext
{
    /**
     * @param (\Closure(string, ?string): void)|null $onStatus         when set, owns registry + status-widget sync
     * @param (\Closure(?string): void)|null         $onWorkingMessage when set, owns registry + widget sync
     * @param (\Closure(bool): void)|null            $onWorkingVisible when set, owns registry + widget sync
     */
    public function __construct(
        private readonly TuiSlotRegistry $registry,
        private readonly ?FooterDataProvider $footerDataProvider = null,
        private readonly ?\Closure $onWorkingMessage = null,
        private readonly ?\Closure $onWorkingVisible = null,
        private readonly ?\Closure $onStatus = null,
    ) {
    }

    public function setStatus(string $key, ?string $text): void
    {
        if (null !== $this->onStatus) {
            ($this->onStatus)($key, $text);

            return;
        }

        $this->registry->setStatus($key, $text);
    }

    public function setWorkingMessage(?string $message): void
    {
        if (null !== $this->onWorkingMessage) {
            ($this->onWorkingMessage)($message);

            return;
        }

        $this->registry->setWorkingMessage($message);
    }

    public function setWorkingVisible(bool $visible): void
    {
        if (null !== $this->onWorkingVisible) {
            ($this->onWorkingVisible)($visible);

            return;
        }

        $this->registry->setWorkingVisible($visible);
    }

    public function setFooterProvider(string $key, ?FooterSegmentProvider $provider): void
    {
        $this->footerDataProvider?->setProvider($key, $provider);
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
