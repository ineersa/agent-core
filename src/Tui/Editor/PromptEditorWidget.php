<?php

declare(strict_types=1);

namespace Ineersa\Tui\Editor;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Static prompt display widget (TuiWidget).
 *
 * Renders the non-interactive prompt placeholder line ("❯ Type a message...").
 * Used by ChatLayout as a TuiWidget renderable. This is NOT the interactive
 * editor — see {@see PromptEditor} for the interactive text input facade that
 * wraps Symfony TUI's EditorWidget for use in ChatScreen.
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

        $styledLine = $context->theme->color(ThemeColorEnum::Prompt, $line);

        if ('' !== $this->promptText) {
            return [$styledLine, ''];
        }

        return [$styledLine];
    }
}
