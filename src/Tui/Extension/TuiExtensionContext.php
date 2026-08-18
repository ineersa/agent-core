<?php

declare(strict_types=1);

namespace Ineersa\Tui\Extension;

use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Layout\InputPriority;

/**
 * Extension UI context — the sole contract between internal extension code and the TUI.
 *
 * Inspired by pi-mono's ExtensionUIContext pattern. Extensions receive an
 * implementation of this interface and use it to register status text,
 * working state, footer segments, and native input handlers.
 *
 * Extensions must NOT mutate widgets directly. All interactions go through
 * these slot-based methods.
 *
 * Widget-replacement methods (header/footer/editor/above-editor widgets)
 * were removed when ChatScreen migrated to directly mounted native widgets;
 * the public ExtensionApi never exposed them.
 */
interface TuiExtensionContext
{
    /**
     * Set or remove a keyed status-panel entry (panel-only; not the footer).
     *
     * @param string      $key  Section identifier
     * @param string|null $text Status text, or null to remove the entry
     */
    public function setStatus(string $key, ?string $text): void;

    /**
     * Override the working/loading message.
     *
     * @param string|null $message New message, or null to clear
     */
    public function setWorkingMessage(?string $message): void;

    /**
     * Show or hide the working indicator row.
     */
    public function setWorkingVisible(bool $visible): void;

    /**
     * Register or remove a footer segment provider under a key.
     *
     * Providers added through this API coexist with the default footer
     * segments.
     *
     * @param string                     $key      Unique key for this provider
     * @param FooterSegmentProvider|null $provider Provider to add, or null to remove
     */
    public function setFooterProvider(string $key, ?FooterSegmentProvider $provider): void;

    /**
     * Register a native terminal input listener.
     *
     * The handler is a Symfony TUI InputEvent listener (first parameter
     * type-hinted with the event class); it may call stopPropagation() to
     * consume the input. The host registers it on the Tui event dispatcher
     * with the given priority, so slot handlers interleave with the other
     * native input listeners. Equal priorities keep registration order.
     *
     * @param callable $handler Symfony TUI InputEvent listener
     */
    public function onTerminalInput(callable $handler, int $priority = InputPriority::EXTENSION_DEFAULT): void;
}
