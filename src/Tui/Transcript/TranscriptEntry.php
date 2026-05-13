<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColor;
use Ineersa\Tui\Widget\TuiRenderContext;

/**
 * A single entry in the conversation transcript.
 *
 * Lightweight value object that holds rendered text and optional metadata.
 */
final readonly class TranscriptEntry
{
    /**
     * @param string $text  The rendered text for this entry
     * @param string $role  'user', 'assistant', 'tool', or 'system'
     * @param string $style Optional hint for styling
     */
    public function __construct(
        public string $text = '',
        public string $role = 'system',
        public string $style = '',
    ) {
    }

    /**
     * Render this entry as a display line, applying theme colors via the context.
     *
     * For backwards compatibility, a render() without context uses no styling.
     */
    public function render(TuiRenderContext $context): string
    {
        $prefix = match ($this->role) {
            'user' => '  ❯',
            'assistant' => '  ◇',
            'tool' => '  ●',
            default => '  ',
        };

        $roleColor = match ($this->role) {
            'user' => ThemeColor::UserMessage,
            'assistant' => ThemeColor::AssistantMessage,
            'tool' => ThemeColor::Tool,
            default => ThemeColor::SystemMessage,
        };

        $line = \sprintf('%s %s', $prefix, $this->text);

        // If user_message has no color, use accent; otherwise use role default
        $colorSpec = $context->theme->color($roleColor, '');

        return $context->theme->color($roleColor, $line);
    }
}
