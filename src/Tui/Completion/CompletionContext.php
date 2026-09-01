<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Context passed to completion providers.
 *
 * Carries the current editor text so providers can determine the active
 * completion token and replacement range. EDITOR-08 always completes against
 * the full editor text because {@see PromptEditor} does not expose live cursor
 * state.
 */
final readonly class CompletionContext
{
    public function __construct(
        public string $text,
    ) {
    }

    /**
     * Convenience factory matching the current editor MVP.
     */
    public static function forCursorAtEnd(string $text): self
    {
        return new self($text);
    }
}
