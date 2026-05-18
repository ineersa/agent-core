<?php

declare(strict_types=1);

namespace Ineersa\Tui\Editor;

use Ineersa\Tui\Theme\ThemeColor;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Renderable prompt editor widget for the TUI widget layout system.
 *
 * Used by {@see ChatLayout} as the default editor renderable in the
 * TuiWidget-based rendering path.  The interactive terminal editor is
 * handled by {@see \Symfony\Component\Tui\Widget\EditorWidget} via
 * {@see ChatScreen}.
 */
final class PromptEditorWidget implements TuiWidget
{
    public function __construct(
        private string $placeholder = 'Type a message...',
        private string $promptText = '',
    ) {
    }

    public function setPlaceholder(string $placeholder): void
    {
        $this->placeholder = $placeholder;
    }

    public function setPromptText(string $text): void
    {
        $this->promptText = $text;
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        $prompt = '' !== $this->promptText ? $this->promptText : $this->placeholder;
        $line = \sprintf('  ❯ %s', $prompt);

        $styledLine = $context->theme->color(ThemeColor::Prompt, $line);

        if ('' !== $this->promptText) {
            return [$styledLine, ''];
        }

        return [$styledLine];
    }
}
