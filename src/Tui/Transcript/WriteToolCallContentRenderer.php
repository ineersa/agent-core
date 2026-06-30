<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Renders write-tool content previews inside ToolCall cards (raw or markdown-aware for .md targets).
 */
final readonly class WriteToolCallContentRenderer
{
    public function __construct(
        private TranscriptLinePreviewService $linePreview = new TranscriptLinePreviewService(),
    ) {
    }

    public function isMarkdownTargetPath(string $path): bool
    {
        return 1 === preg_match('/\.(md|markdown)$/i', $path);
    }

    /**
     * @return list<AbstractWidget>
     */
    public function buildContentBodyWidgets(
        string $content,
        string $path,
        TuiTheme $theme,
        TranscriptDisplayConfig $displayConfig,
        TranscriptDisplayState $displayState,
    ): array {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $rawLines = explode("\n", $normalized);
        $preview = $this->linePreview->apply(
            $rawLines,
            $displayConfig->diffPreviewLines,
            fullRender: false,
            displayState: $displayState,
        );

        $previewText = implode("\n", $preview['lines']);
        if ('' === $previewText && '' === $content) {
            return [];
        }

        if ($this->isMarkdownTargetPath($path)) {
            $widgets = [$this->buildMarkdownPreviewWidget($previewText, $theme)];
        } else {
            $body = $this->buildPlaintextBodyWidget($preview['lines'], $theme);
            $widgets = null !== $body ? [$body] : [];
        }

        if (null !== $preview['ellipsis']) {
            $widgets[] = new TextWidget($theme->color(ThemeColorEnum::ToolOutput, '    '.$preview['ellipsis']));
        }

        return $widgets;
    }

    /**
     * @param list<string> $lines
     */
    private function buildPlaintextBodyWidget(array $lines, TuiTheme $theme): ?TextWidget
    {
        if ([] === $lines) {
            return null;
        }

        $coloredLines = [];
        foreach ($lines as $line) {
            $coloredLines[] = $theme->color(ThemeColorEnum::ToolOutput, '    '.$line);
        }

        return new TextWidget(implode("\n", $coloredLines));
    }

    private function buildMarkdownPreviewWidget(string $previewText, TuiTheme $theme): MarkdownWidget
    {
        $mdWidget = new MarkdownWidget($previewText);
        $colorSpec = $theme->getPalette()->get(ThemeColorEnum::ToolOutput);
        $style = '' !== $colorSpec
            ? new Style(color: $colorSpec, padding: Padding::from([0, 0, 0, 4]))
            : new Style(padding: Padding::from([0, 0, 0, 4]));
        $mdWidget->setStyle($style);

        return $mdWidget;
    }
}
