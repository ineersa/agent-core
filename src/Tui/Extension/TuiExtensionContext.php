<?php

declare(strict_types=1);

namespace Ineersa\Tui\Extension;

use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Widget\TuiWidget;
use Ineersa\Tui\Widget\WidgetPlacementEnum;

/**
 * Extension UI context — the sole contract between extension code and the TUI.
 *
 * Inspired by pi-mono's ExtensionUIContext pattern. Extensions receive an
 * implementation of this interface and use it to register custom slots
 * (header, footer, editor, widgets), status text, and input handlers.
 *
 * Extensions must NOT mutate widgets directly. All interactions go through
 * these slot-based methods.
 */
interface TuiExtensionContext
{
    /**
     * Replace the header widget entirely.
     *
     * @param TuiWidget|null $widget New header, or null to restore the default
     */
    public function setHeader(?TuiWidget $widget): void;

    /**
     * Replace the footer bar widget entirely.
     *
     * @param TuiWidget|null $widget New footer, or null to restore the default
     */
    public function setFooter(?TuiWidget $widget): void;

    /**
     * Replace the prompt editor component.
     *
     * @param TuiWidget|null $widget New editor, or null to restore the default
     */
    public function setEditorComponent(?TuiWidget $widget): void;

    /**
     * Add or remove an extension widget.
     *
     * @param string          $key       Unique identifier for this widget
     * @param TuiWidget|null  $content   Widget to add, or null to remove
     * @param WidgetPlacementEnum $placement Where the widget should appear
     */
    public function setWidget(string $key, ?TuiWidget $content, WidgetPlacementEnum $placement = WidgetPlacementEnum::AboveEditor): void;

    /**
     * Set or remove a status text entry.
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
     * segments. To replace the entire footer widget, use setFooter().
     *
     * @param string                     $key      Unique key for this provider
     * @param FooterSegmentProvider|null $provider Provider to add, or null to remove
     */
    public function setFooterProvider(string $key, ?FooterSegmentProvider $provider): void;

    /**
     * Register a raw terminal input handler.
     *
     * @param callable(string $data): void $handler
     */
    public function onTerminalInput(callable $handler): void;
}
