<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Width-aware tool-result body that previews rendered terminal rows.
 */
final class TranscriptToolResultPreviewWidget extends AbstractWidget
{
    public const int COLLAPSED_LINE_LIMIT = 4;

    public function __construct(
        private readonly string $body,
        private readonly int $lineLimit,
        private readonly bool $fullRender,
        private readonly bool $fromEnd,
        private readonly bool $prependBlankLine,
        private readonly TranscriptDisplayState $displayState,
        private readonly TranscriptLinePreviewService $linePreviewService,
        private readonly TuiTheme $theme,
        private readonly ThemeColorEnum $color,
    ) {
        $this->setStyle(new Style(padding: Padding::from([0, 0, 0, 4])));
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $styledLines = [];
        foreach (explode("\n", $this->body) as $line) {
            $styledLines[] = $this->theme->color($this->color, $line);
        }

        $renderedLines = TextWrapper::wrapTextWithAnsi(
            implode("\n", $styledLines),
            max(1, $context->getColumns()),
        );
        $preview = $this->linePreviewService->apply(
            $renderedLines,
            $this->lineLimit,
            $this->fullRender,
            $this->displayState,
            $this->fromEnd,
        );

        $lines = [];
        if ($this->prependBlankLine) {
            $lines[] = '';
        }
        if ($this->fromEnd && null !== $preview['ellipsis']) {
            $lines[] = TranscriptPreviewEllipsis::style($this->theme, $preview['ellipsis']);
        }
        array_push($lines, ...$preview['lines']);
        if (!$this->fromEnd && null !== $preview['ellipsis']) {
            $lines[] = TranscriptPreviewEllipsis::style($this->theme, $preview['ellipsis']);
        }

        return $lines;
    }
}
