<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\TextWidget;

/** Clips native rendered rows, not Markdown source lines or byte offsets. */
final class TranscriptBodyPreviewWidget extends AbstractWidget implements ParentInterface
{
    public function __construct(private readonly AbstractWidget $body, private readonly TuiTheme $theme, private readonly bool $detailsHidden = false)
    {
        $this->attachChild($body);
    }

    public function all(): array
    {
        return [$this->body];
    }

    public function render(RenderContext $context): array
    {
        $widgets = $this->getContext();
        if (null === $widgets) {
            throw new \LogicException('Transcript previews must be mounted before rendering.');
        }
        $lines = $widgets->renderWidget($this->body, $context);
        $preview = (new TranscriptLinePreviewService())->apply($lines, 6, new TranscriptDisplayState());
        $truncated = null !== $preview['ellipsis'];
        if (!$truncated && !$this->detailsHidden) {
            return $lines;
        }

        // TextWidget owns ANSI-aware wrapping of the hint on narrow terminals too.
        $hint = new TextWidget($this->theme->color(ThemeColorEnum::Dim, $truncated ? '  ... Ctrl+O to expand' : '  Ctrl+O for details'));

        return [...$preview['lines'], ...$hint->render($context)];
    }
}
