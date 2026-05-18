<?php

declare(strict_types=1);

namespace Ineersa\Tui\Editor;

/**
 * Immutable value object holding the editor's text state.
 *
 * Lines are logical lines split by \n. The constructor enforces invariants:
 *   - lines is never empty (minimum [''])
 *   - cursorLine / cursorColumn are always in bounds
 *   - scrollOffset is non-negative
 */
final readonly class EditorState
{
    /**
     * @param list<string> $lines        Logical lines, split by \n. Always at least [''].
     * @param int          $cursorLine   0-indexed line number.
     * @param int          $cursorColumn 0-indexed character column within the line.
     * @param int          $scrollOffset Viewport scroll offset (for future use).
     */
    public function __construct(
        public array $lines,
        public int $cursorLine,
        public int $cursorColumn,
        public int $scrollOffset,
    ) {
        if ([] === $this->lines) {
            throw new \InvalidArgumentException('Lines must not be empty.');
        }

        if ($this->cursorLine < 0 || $this->cursorLine >= \count($this->lines)) {
            throw new \OutOfBoundsException(\sprintf(
                'Cursor line %d out of bounds [0, %d).',
                $this->cursorLine,
                \count($this->lines),
            ));
        }

        $lineLength = \mb_strlen($this->lines[$this->cursorLine], 'UTF-8');

        if ($this->cursorColumn < 0 || $this->cursorColumn > $lineLength) {
            throw new \OutOfBoundsException(\sprintf(
                'Cursor column %d out of bounds [0, %d] for line %d.',
                $this->cursorColumn,
                $lineLength,
                $this->cursorLine,
            ));
        }

        if ($this->scrollOffset < 0) {
            throw new \InvalidArgumentException('Scroll offset must not be negative.');
        }
    }

    /**
     * Create initial state from a text string.
     *
     * An empty string produces lines = [''] with cursor at (0, 0).
     */
    public static function fromText(string $text): self
    {
        $lines = '' === $text ? [''] : \explode("\n", $text);

        return new self($lines, 0, 0, 0);
    }

    /**
     * Return a copy with replaced lines and updated cursor position.
     *
     * @param list<string> $lines
     */
    public function withLines(array $lines, int $cursorLine, int $cursorColumn): self
    {
        return new self($lines, $cursorLine, $cursorColumn, $this->scrollOffset);
    }

    /**
     * Return a copy with updated cursor position.
     */
    public function withCursor(int $cursorLine, int $cursorColumn): self
    {
        return new self($this->lines, $cursorLine, $cursorColumn, $this->scrollOffset);
    }

    /**
     * Return a copy with updated scroll offset.
     */
    public function withScrollOffset(int $scrollOffset): self
    {
        return new self($this->lines, $this->cursorLine, $this->cursorColumn, $scrollOffset);
    }
}
