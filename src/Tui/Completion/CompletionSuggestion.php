<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * A single completion suggestion from a provider.
 *
 * Carries enough information for rendering in the completion menu
 * and for replacing text in the editor when accepted.
 */
final readonly class CompletionSuggestion
{
    /**
     * @param string $display           Text shown in the completion menu (e.g. "/help")
     * @param string $insertText        Text to insert when accepted (e.g. "/help ")
     * @param string $description       Helper description (e.g. "Show available commands")
     * @param int    $replacementStart  Start position (0-based byte offset) in the full editor text
     * @param int    $replacementLength Number of bytes to replace starting at replacementStart
     */
    public function __construct(
        public string $display,
        public string $insertText,
        public string $description,
        public int $replacementStart,
        public int $replacementLength,
    ) {
    }
}
