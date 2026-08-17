<?php

declare(strict_types=1);

namespace Ineersa\Tui\Layout;

use Ineersa\Tui\Widget\TuiWidget;
use Ineersa\Tui\Widget\WidgetPlacementEnum;

/**
 * Central registry for all replaceable TUI slots.
 *
 * Stores:
 *   - Optional replacement header, footer, and editor widgets.
 *   - Extension-provided widgets keyed by identifier with placement.
 *   - Status text entries keyed by section name.
 *   - Working message text and visibility flag.
 *   - Terminal input handler entries (priority + native InputEvent listener).
 *
 * Default widgets are supplied by ChatScreen; the registry only
 * records overrides.
 */
final class TuiSlotRegistry
{
    /** Default render order: widgets with equal order keep insertion order. */
    public const ORDER_DEFAULT = 0;

    /** Pin a widget to render LAST within its placement (adjacent to the editor for AboveEditor). */
    public const ORDER_PINNED_LAST = \PHP_INT_MAX;

    /** @var array<string, array{widget: TuiWidget, placement: WidgetPlacementEnum, order: int}> */
    private array $extensionWidgets = [];

    /** @var array<string, string> */
    private array $statusEntries = [];

    /**
     * Native TUI InputEvent listeners registered by the host.
     *
     * Each entry is a callable receiving a Symfony InputEvent (so it can
     * stop propagation) plus the priority it must be registered with, so
     * slot handlers interleave with the other native listeners by priority.
     *
     * @var list<array{priority: int, handler: callable}>
     */
    private array $inputHandlers = [];

    private ?TuiWidget $header = null;
    private ?TuiWidget $footer = null;
    private ?TuiWidget $editorComponent = null;
    private string $workingMessage = '';
    private bool $workingVisible = true;

    /* ───────── Header ───────── */

    public function setHeader(?TuiWidget $widget): void
    {
        $this->header = $widget;
    }

    public function getHeader(): ?TuiWidget
    {
        return $this->header;
    }

    /* ───────── Footer ───────── */

    public function setFooter(?TuiWidget $widget): void
    {
        $this->footer = $widget;
    }

    public function getFooter(): ?TuiWidget
    {
        return $this->footer;
    }

    /* ───────── Editor ───────── */

    public function setEditorComponent(?TuiWidget $widget): void
    {
        $this->editorComponent = $widget;
    }

    public function getEditorComponent(): ?TuiWidget
    {
        return $this->editorComponent;
    }

    /* ───────── Extension widgets ───────── */

    public function setWidget(
        string $key,
        TuiWidget $widget,
        WidgetPlacementEnum $placement = WidgetPlacementEnum::AboveEditor,
        int $order = self::ORDER_DEFAULT,
    ): void {
        $this->extensionWidgets[$key] = ['widget' => $widget, 'placement' => $placement, 'order' => $order];

        // Maintain ascending order on mutation so render reads stay sorted.
        // usort is stable (PHP 8.0+), so equal orders preserve insertion order.
        uasort($this->extensionWidgets, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);
    }

    public function removeWidget(string $key): void
    {
        unset($this->extensionWidgets[$key]);
    }

    /**
     * Widgets for a placement, in ascending `order` (maintained by
     * {@see setWidget()} on mutation).
     *
     * Lower order renders first (top of the merged block); higher order
     * renders last (adjacent to the editor for AboveEditor). Equal orders
     * preserve insertion order (stable sort on mutation).
     *
     * @return list<TuiWidget>
     */
    public function getWidgetsByPlacement(WidgetPlacementEnum $placement): array
    {
        $result = [];
        foreach ($this->extensionWidgets as $entry) {
            if ($entry['placement'] === $placement) {
                $result[] = $entry['widget'];
            }
        }

        return $result;
    }

    /* ───────── Status entries ───────── */

    public function setStatus(string $key, ?string $text): void
    {
        if (null === $text) {
            unset($this->statusEntries[$key]);
        } else {
            $this->statusEntries[$key] = $text;
        }
    }

    /**
     * @return array<string, string>
     */
    public function getStatusEntries(): array
    {
        return $this->statusEntries;
    }

    /* ───────── Working state ───────── */

    public function setWorkingMessage(?string $message): void
    {
        $this->workingMessage = $message ?? '';
    }

    public function getWorkingMessage(): string
    {
        return $this->workingMessage;
    }

    public function setWorkingVisible(bool $visible): void
    {
        $this->workingVisible = $visible;
    }

    public function isWorkingVisible(): bool
    {
        return $this->workingVisible;
    }

    /* ───────── Input handlers ───────── */

    /**
     * Register a native TUI InputEvent listener.
     *
     * The handler is a Symfony InputEvent listener: its first parameter
     * must be type-hinted with the event class so the host can register it
     * via {@see \Symfony\Component\Tui\Tui::addListener()} with the given
     * priority. Equal priorities keep registration order.
     *
     * @param callable $handler Symfony InputEvent listener
     */
    public function addInputHandler(callable $handler, int $priority = InputPriority::EXTENSION_DEFAULT): void
    {
        $this->inputHandlers[] = ['priority' => $priority, 'handler' => $handler];
    }

    /**
     * Registered native input listeners in registration order.
     *
     * @return list<array{priority: int, handler: callable}>
     */
    public function getInputHandlers(): array
    {
        return $this->inputHandlers;
    }
}
