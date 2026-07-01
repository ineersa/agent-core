<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
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

    public function buildPatchBodyWidget(
        string $patch,
        TuiTheme $theme,
        TranscriptDisplayConfig $displayConfig,
        TranscriptDisplayState $displayState,
    ): ?TextWidget {
        $rawLines = explode("\n", str_replace(["\r\n", "\r"], "\n", $patch));
        $preview = $this->linePreview->apply(
            $rawLines,
            $displayConfig->diffPreviewLines,
            fullRender: false,
            displayState: $displayState,
        );

        $coloredLines = [];
        foreach ($preview['lines'] as $line) {
            $coloredLines[] = $theme->color($this->colorForPatchLine($line), '    '.$line);
        }

        if (null !== $preview['ellipsis']) {
            $coloredLines[] = $theme->color(ThemeColorEnum::DiffContext, '    '.$preview['ellipsis']);
        }

        if ([] === $coloredLines) {
            return null;
        }

        return new TextWidget(implode("\n", $coloredLines));
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
