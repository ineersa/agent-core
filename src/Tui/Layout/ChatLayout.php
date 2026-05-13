<?php

declare(strict_types=1);

namespace Ineersa\Tui\Layout;

use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;
use Ineersa\Tui\Widget\WidgetPlacement;

/**
 * Assembles the TUI layout from registered widgets.
 *
 * Render order (top to bottom):
 *   1. Header
 *   2. Separator
 *   3. Transcript
 *   4. Pending messages (if non-empty)
 *   5. Working status
 *   6. Status panel (from setStatus entries)
 *   7. Above-editor extension widgets
 *   8. Editor separator
 *   9. Editor
 *  10. Below-editor extension widgets
 *  11. Footer separator
 *  12. Footer
 */
final class ChatLayout
{
    /**
     * @param TuiSlotRegistry $registry        Slot registry with optional overrides and extension data
     * @param TuiWidget       $header          Default header widget
     * @param TuiWidget       $transcript      Transcript/history widget
     * @param TuiWidget       $pendingMessages Pending messages widget
     * @param TuiWidget       $workingStatus   Working/status indicator widget
     * @param TuiWidget       $editor          Default prompt editor widget
     * @param TuiWidget       $footer          Default footer bar widget
     */
    public function __construct(
        private readonly TuiSlotRegistry $registry,
        private readonly TuiWidget $header,
        private readonly TuiWidget $transcript,
        private readonly TuiWidget $pendingMessages,
        private readonly TuiWidget $workingStatus,
        private readonly TuiWidget $editor,
        private readonly TuiWidget $footer,
    ) {
    }

    /**
     * Render the full layout.
     *
     * @return list<string>
     */
    public function render(TuiRenderContext $context): array
    {
        $lines = [];

        // 1. Header (replacement or default)
        $headerWidget = $this->registry->getHeader() ?? $this->header;
        $lines = array_merge($lines, $headerWidget->render($context));

        // 2. Separator after header
        if ([] !== $lines) {
            $lines[] = str_repeat('─', $context->terminalWidth);
        }

        // 3. Transcript / history
        $lines = array_merge($lines, $this->transcript->render($context));

        // 4. Pending messages (only if non-empty)
        $pendingLines = $this->pendingMessages->render($context);
        if ([] !== $pendingLines) {
            $lines[] = '';
            $lines = array_merge($lines, $pendingLines);
        }

        // 5. Working status
        $workingLines = $this->workingStatus->render($context);
        if ([] !== $workingLines) {
            $lines = array_merge($lines, $workingLines);
        }

        // 6. Status panel (keyed status entries)
        $statusLines = $this->renderStatusPanel($context);
        if ([] !== $statusLines) {
            $lines = array_merge($lines, $statusLines);
        }

        // 7. Above-editor extension widgets
        foreach ($this->registry->getWidgetsByPlacement(WidgetPlacement::AboveEditor) as $widget) {
            $lines = array_merge($lines, $widget->render($context));
        }

        // 8. Editor separator
        $lines[] = str_repeat('─', $context->terminalWidth);

        // 9. Editor (replacement or default)
        $editorWidget = $this->registry->getEditorComponent() ?? $this->editor;
        $lines = array_merge($lines, $editorWidget->render($context));

        // 10. Below-editor extension widgets
        foreach ($this->registry->getWidgetsByPlacement(WidgetPlacement::BelowEditor) as $widget) {
            $lines = array_merge($lines, $widget->render($context));
        }

        // 11. Footer separator
        $lines[] = str_repeat('─', $context->terminalWidth);

        // 12. Footer (replacement or default)
        $footerWidget = $this->registry->getFooter() ?? $this->footer;
        $lines = array_merge($lines, $footerWidget->render($context));

        return $lines;
    }

    /**
     * Render the status panel from keyed status entries.
     *
     * @return list<string>
     */
    private function renderStatusPanel(TuiRenderContext $context): array
    {
        $entries = $this->registry->getStatusEntries();
        if ([] === $entries) {
            return [];
        }

        $lines = [];
        foreach ($entries as $key => $text) {
            $lines[] = \sprintf('%-12s %s', $key, $text);
        }

        return $lines;
    }
}
