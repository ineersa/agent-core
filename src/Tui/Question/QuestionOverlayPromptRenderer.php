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
 * Compact markdown prompt rendering for active HITL overlays (QuestionController).
 */
final class QuestionOverlayPromptRenderer
{
    public function buildPromptWidget(string $prompt, TuiTheme $theme): MarkdownWidget
    {
        $mdWidget = new MarkdownWidget($prompt);
        // Default/inherited foreground for prompt body; accent is reserved for the compact header line.
        $mdWidget->setStyle(new Style(padding: Padding::from([0, 0, 0, 2])));

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
