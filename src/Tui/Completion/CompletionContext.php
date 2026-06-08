<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Forward-compatible context passed to completion providers.
 *
 * Carries the current editor text and cursor position so providers
 * can determine the active completion token and replacement range.
 *
 * EDITOR-08 uses cursor-at-end because {@see PromptEditor} does not
 * expose live cursor state.  When cursor tracking is available in a
 * future editor phase, providers that receive a real cursor offset
 * can honour it without interface churn.
 */
final readonly class CompletionContext
{
    /**
     * @param string $text             Full editor text
     * @param int    $cursorByteOffset 0-based byte position of the cursor within $text
     */
    public function __construct(
        public string $text,
        public int $cursorByteOffset,
    ) {
    }

    /**
     * Convenience factory for the current EDITOR-08 MVP where the
     * cursor is always at the end of the editor text.
     */
    public static function forCursorAtEnd(string $text): self
    {
        return new self($text, \strlen($text));
    }
}
