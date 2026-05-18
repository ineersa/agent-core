<?php

declare(strict_types=1);

namespace Ineersa\Tui\Editor;

/**
 * Lightweight immutable snapshot of editor text state.
 *
 * Useful for test fixtures, session persistence (EDITOR-07), and
 * transferring text state without coupling to Symfony TUI internals.
 *
 * Lines are logical lines split by \n. The constructor enforces
 * only that lines is non-empty.
 *
 * This is a pure DTO — cursor position tracking and text mutation
 * are delegated to Symfony TUI's {@see EditorWidget} / {@see EditorDocument}.
 */
final readonly class EditorState
{
    /**
     * @param list<string> $lines        logical lines, always at least ['']
     * @param int          $cursorLine   0-indexed. Not validated — caller responsibility.
     * @param int          $cursorColumn 0-indexed character column. Not validated — caller responsibility.
     */
    public function __construct(
        public array $lines,
        public int $cursorLine = 0,
        public int $cursorColumn = 0,
    ) {
        if ([] === $this->lines) {
            throw new \InvalidArgumentException('Lines must not be empty.');
        }
    }

    /**
     * Create state from a text string.
     */
    public static function fromText(string $text): self
    {
        $lines = '' === $text ? [''] : explode("\n", $text);

        return new self($lines, 0, 0);
    }

    /**
     * Create empty state.
     */
    public static function empty(): self
    {
        return new self([''], 0, 0);
    }

    /**
     * Return the full text by joining logical lines with \n.
     */
    public function getText(): string
    {
        return implode("\n", $this->lines);
    }

    /**
     * True when the editor contains only a single empty line.
     */
    public function isEmpty(): bool
    {
        return 1 === \count($this->lines)
            && '' === $this->lines[0];
    }
}
