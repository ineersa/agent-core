<?php

declare(strict_types=1);

namespace Ineersa\Tui\Editor;

use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Default prompt editor widget.
 *
 * For v1, renders a static prompt area with placeholder text.
 * Future versions will wire an actual Symfony TUI InputWidget.
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

        // If the prompt has content, show a second line for cursor
        if ('' !== $this->promptText) {
            return [$line, ''];
        }

        return [$line];
    }
}
