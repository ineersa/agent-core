<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Shared compact markdown prompt rendering for HITL overlays and transcript Question blocks.
 */
final class QuestionOverlayPromptRenderer
{
    public function buildPromptWidget(string $prompt, TuiTheme $theme): MarkdownWidget
    {
        $mdWidget = new MarkdownWidget($prompt);
        $colorSpec = $theme->getPalette()->get(ThemeColorEnum::Accent);
        $style = '' !== $colorSpec
            ? new Style(color: $colorSpec, padding: Padding::from([0, 0, 0, 2]))
            : new Style(padding: Padding::from([0, 0, 0, 2]));
        $mdWidget->setStyle($style);

        return $mdWidget;
    }

    public function buildIndentedHeader(string $text, TuiTheme $theme): TextWidget
    {
        return new TextWidget(
            text: $theme->color(ThemeColorEnum::Accent, '  '.$text),
            truncate: false,
        );
    }

    public function buildIndentedHint(string $text, TuiTheme $theme): TextWidget
    {
        return new TextWidget(
            text: $theme->muted('  '.$text),
            truncate: false,
        );
    }
}
