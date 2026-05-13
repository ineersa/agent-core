<?php

declare(strict_types=1);

namespace Ineersa\Tui\Layout;

use Ineersa\Tui\Widget\TuiWidget;
use Ineersa\Tui\Widget\WidgetPlacement;

/**
 * Central registry for all replaceable TUI slots.
 *
 * Stores:
 *   - Optional replacement header, footer, and editor widgets.
 *   - Extension-provided widgets keyed by identifier with placement.
 *   - Status text entries keyed by section name.
 *   - Working message text and visibility flag.
 *   - Terminal input intercept handlers.
 *
 * Default widgets are supplied by ChatLayout; the registry only
 * records overrides.
 */
final class TuiSlotRegistry
{
    /** @var array<string, array{widget: TuiWidget, placement: WidgetPlacement}> */
    private array $extensionWidgets = [];

    /** @var array<string, string> */
    private array $statusEntries = [];

    /** @var list<callable> */
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

    public function setWidget(string $key, TuiWidget $widget, WidgetPlacement $placement = WidgetPlacement::AboveEditor): void
    {
        $this->extensionWidgets[$key] = ['widget' => $widget, 'placement' => $placement];
    }

    public function removeWidget(string $key): void
    {
        unset($this->extensionWidgets[$key]);
    }

    /**
     * @return list<TuiWidget>
     */
    public function getWidgetsByPlacement(WidgetPlacement $placement): array
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

    public function addInputHandler(callable $handler): void
    {
        $this->inputHandlers[] = $handler;
    }

    /**
     * @return list<callable>
     */
    public function getInputHandlers(): array
    {
        return $this->inputHandlers;
    }
}
