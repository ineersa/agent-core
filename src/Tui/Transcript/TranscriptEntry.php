<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
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
            'user' => ThemeColorEnum::UserMessage,
            'assistant' => ThemeColorEnum::AssistantMessage,
            'tool' => ThemeColorEnum::Tool,
            default => ThemeColorEnum::SystemMessage,
        };

        $line = \sprintf('%s %s', $prefix, $this->text);

        return match ($this->style) {
            'accent' => $context->theme->accent($line),
            'muted' => $context->theme->muted($line),
            'warning' => $context->theme->warning($line),
            'error' => $context->theme->error($line),
            default => $context->theme->color($roleColor, $line),
        };
    }
}
