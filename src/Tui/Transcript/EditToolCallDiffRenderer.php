<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Renders edit-tool patch arguments as colored unified-diff lines inside ToolCall cards.
 */
final readonly class EditToolCallDiffRenderer
{
    public function __construct(
        private TranscriptLinePreviewService $linePreview = new TranscriptLinePreviewService(),
    ) {
    }

    /**
     * @return list<AbstractWidget>
     */
    public function buildPatchBodyWidgets(
        string $patch,
        TuiTheme $theme,
        TranscriptDisplayConfig $displayConfig,
        TranscriptDisplayState $displayState,
    ): array {
        $rawLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $patch));
        $preview = $this->linePreview->apply(
            $rawLines,
            $displayConfig->diffPreviewLines,
            fullRender: false,
            displayState: $displayState,
        );

        $widgets = [];
        foreach ($preview['lines'] as $line) {
            $widgets[] = new TextWidget($theme->color($this->colorForPatchLine($line), '    '.$line));
        }

        if (null !== $preview['ellipsis']) {
            $widgets[] = new TextWidget($theme->color(ThemeColorEnum::DiffContext, '    '.$preview['ellipsis']));
        }

        return $widgets;
    }

    private function colorForPatchLine(string $line): ThemeColorEnum
    {
        if (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
            return ThemeColorEnum::DiffAdded;
        }

        if (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
            return ThemeColorEnum::DiffRemoved;
        }

        return ThemeColorEnum::DiffContext;
    }
}
