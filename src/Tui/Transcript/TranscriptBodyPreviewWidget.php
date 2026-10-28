<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\TextWidget;

/** Width-aware previews of tool output or native rendered bodies, not source lines. */
final class TranscriptBodyPreviewWidget extends AbstractWidget implements ParentInterface
{
    public function __construct(
        private readonly string|AbstractWidget $body,
        private readonly TranscriptDisplayState $displayState,
        private readonly TranscriptLinePreviewService $linePreviewService,
        private readonly TuiTheme $theme,
        private readonly int $lineLimit = 6,
        private readonly bool $fromEnd = false,
        private readonly bool $prependBlankLine = false,
        private readonly ThemeColorEnum $color = ThemeColorEnum::ToolOutput,
        private readonly bool $detailsHidden = false,
        private readonly ?string $expandHint = null,
    ) {
        if ($body instanceof AbstractWidget) {
            $this->attachChild($body);
        } else {
            $this->setStyle(new Style(padding: Padding::from([0, 0, 0, 4])));
        }
    }

    public function all(): array
    {
        return $this->body instanceof AbstractWidget ? [$this->body] : [];
    }

    public function render(RenderContext $context): array
    {
        if ($this->body instanceof AbstractWidget) {
            $widgets = $this->getContext();
            if (null === $widgets) {
                throw new \LogicException('Transcript previews must be mounted before rendering.');
            }
            $renderedLines = $widgets->renderWidget($this->body, $context);
        } else {
            $body = str_replace("\t", str_repeat(' ', AnsiUtils::TAB_WIDTH), $this->body);
            $styledLines = [];
            foreach (explode("\n", $body) as $line) {
                $styledLines[] = $this->theme->color($this->color, $line);
            }
            $renderedLines = TextWrapper::wrapTextWithAnsi(implode("\n", $styledLines), max(1, $context->getColumns()));
        }

        $preview = $this->linePreviewService->apply($renderedLines, $this->lineLimit, $this->displayState, $this->fromEnd);
        $hint = null !== $preview['ellipsis'] ? ($this->expandHint ?? $preview['ellipsis']) : null;
        if (null === $hint && $this->detailsHidden && !$this->displayState->previewableBlocksExpanded) {
            $hint = '  Ctrl+O for details';
        }

        // TextWidget also wraps the hint on narrow terminals.
        $hintLines = null === $hint ? [] : (new TextWidget(null === $this->expandHint
            ? TranscriptPreviewEllipsis::style($this->theme, $hint)
            : $this->theme->color(ThemeColorEnum::Dim, $hint)))->render($context);

        return [
            ...($this->prependBlankLine ? [''] : []),
            ...($this->fromEnd ? $hintLines : []),
            ...$preview['lines'],
            ...($this->fromEnd ? [] : $hintLines),
        ];
    }
}
