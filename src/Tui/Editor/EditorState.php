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
 * only that lines is non-empty. The $lines array is privately held
 * and accessed via {@see getLines()} to guarantee immutability.
 *
 * This is a pure DTO — text mutation and cursor tracking are
 * delegated to Symfony TUI's {@see EditorWidget} / {@see EditorDocument}.
 *
 * @todo EDITOR-07: Add cursor position fields when session persistence
 *       needs to capture and restore live cursor from EditorDocument.
 */
final readonly class EditorState
{
    /**
     * @param list<string> $lines logical lines, always at least ['']
     */
    public function __construct(
        private array $lines,
    ) {
        if ([] === $this->lines) {
            throw new \InvalidArgumentException('Lines must not be empty.');
        }
    }

    /**
     * Create state from a text string.
     *
     * Normalizes line endings (\r\n, \r → \n) then splits on \n,
     * matching EditorDocument::setText() behavior.
     */
    public static function fromText(string $text): self
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = '' === $text ? [''] : explode("\n", $text);

        return new self($lines);
    }

    /**
     * Create empty state.
     */
    public static function empty(): self
    {
        return new self(['']);
    }

    /**
     * Return the logical lines.
     *
     * @return list<string>
     */
    public function getLines(): array
    {
        return $this->lines;
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
