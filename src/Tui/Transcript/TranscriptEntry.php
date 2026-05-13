<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

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
     * Render this entry as a display line.
     */
    public function render(): string
    {
        $prefix = match ($this->role) {
            'user' => '  ❯',
            'assistant' => '  ◇',
            'tool' => '  ●',
            default => '  ',
        };

        return \sprintf('%s %s', $prefix, $this->text);
    }
}
