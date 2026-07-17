<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Add a message to the conversation transcript.
 */
final readonly class TranscriptMessage implements CommandResult
{
    /**
     * @param string $text  The message text to display
     * @param string $role  Role hint: 'user', 'assistant', 'tool', 'system', or 'error'
     * @param string $style Optional styling hint (e.g. 'accent', 'muted', 'error'); 'markdown' selects Markdown rendering
     */
    public function __construct(
        public string $text,
        public string $role = 'system',
        public string $style = '',
    ) {
    }
}
