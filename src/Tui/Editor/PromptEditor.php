<?php

declare(strict_types=1);

namespace Ineersa\Tui\Editor;

/**
 * Stateful editor model that wraps an immutable EditorState.
 *
 * Operations mutate the internal state. Cursor movement is character-aware
 * (using mb_* functions for multi-byte UTF-8 safety).
 */
final class PromptEditor
{
    private EditorState $state;

    public function __construct(string $initialText = '')
    {
        $this->state = EditorState::fromText($initialText);
    }

    // ─── Text access ────────────────────────────────────────────

    /**
     * Return the full editor text by joining logical lines with \n.
     */
    public function getText(): string
    {
        return implode("\n", $this->state->lines);
    }

    /**
     * Reset the editor state from a text string.
     *
     * Cursor is placed at (0, 0) and scroll reset to zero.
     */
    public function setText(string $text): void
    {
        $this->state = EditorState::fromText($text);
    }

    // ─── Editing operations ─────────────────────────────────────

    /**
     * Insert text at the current cursor position.
     *
     * Embedded newlines (\n) split the current line.  The cursor is
     * placed after the last inserted character.
     */
    public function insertText(string $text): void
    {
        if ('' === $text) {
            return;
        }

        $parts = explode("\n", $text);
        $lines = $this->state->lines;
        $currentLine = $lines[$this->state->cursorLine];
        $col = $this->state->cursorColumn;

        $left = mb_substr($currentLine, 0, $col, 'UTF-8');
        $right = mb_substr($currentLine, $col, null, 'UTF-8');

        if (1 === \count($parts)) {
            // Single-line insert: just splice into the current line.
            $lines[$this->state->cursorLine] = $left.$parts[0].$right;

            $this->state = $this->state->withLines(
                $lines,
                $this->state->cursorLine,
                $col + mb_strlen($parts[0], 'UTF-8'),
            );

            return;
        }

        // Multi-line insert: parts[0] stays on the current line,
        // middle parts become new lines, parts[last] is prefix of the
        // continuation line.
        $first = $parts[0];
        $last = $parts[\count($parts) - 1];
        $middle = \array_slice($parts, 1, \count($parts) - 2);

        $newLines = [];

        // Copy lines before the cursor line.
        for ($i = 0; $i < $this->state->cursorLine; ++$i) {
            $newLines[] = $lines[$i];
        }

        // Current line becomes left + first inserted part.
        $newLines[] = $left.$first;

        // Middle parts are new, independent lines.
        foreach ($middle as $mid) {
            $newLines[] = $mid;
        }

        // Continuation line: last part + right side of original line.
        $newLines[] = $last.$right;

        // Copy remaining original lines after the cursor line.
        for ($i = $this->state->cursorLine + 1, $max = \count($lines); $i < $max; ++$i) {
            $newLines[] = $lines[$i];
        }

        $newCursorLine = $this->state->cursorLine + \count($parts) - 1;
        $newCursorColumn = mb_strlen($last, 'UTF-8');

        $this->state = $this->state->withLines($newLines, $newCursorLine, $newCursorColumn);
    }

    /**
     * Delete the character before the cursor.
     *
     * If the cursor is at column 0 and not on the first line, the
     * current line is merged into the previous line.
     */
    public function deleteBackward(): void
    {
        $lines = $this->state->lines;
        $line = $lines[$this->state->cursorLine];
        $col = $this->state->cursorColumn;

        if ($col > 0) {
            // Delete one character before the cursor on the same line.
            $before = mb_substr($line, 0, $col - 1, 'UTF-8');
            $after = mb_substr($line, $col, null, 'UTF-8');
            $lines[$this->state->cursorLine] = $before.$after;

            $this->state = $this->state->withLines($lines, $this->state->cursorLine, $col - 1);

            return;
        }

        if ($this->state->cursorLine > 0) {
            // Merge current line into the end of the previous line.
            $prevLine = $lines[$this->state->cursorLine - 1];
            $prevLen = mb_strlen($prevLine, 'UTF-8');

            $merged = $prevLine.$line;
            array_splice($lines, $this->state->cursorLine, 1);
            $lines[$this->state->cursorLine - 1] = $merged;

            $this->state = $this->state->withLines($lines, $this->state->cursorLine - 1, $prevLen);
        }
    }

    /**
     * Delete the character at (after) the cursor.
     *
     * If the cursor is at the end of the line and not on the last
     * line, the next line is merged into the current line.
     */
    public function deleteForward(): void
    {
        $lines = $this->state->lines;
        $line = $lines[$this->state->cursorLine];
        $col = $this->state->cursorColumn;
        $lineLen = mb_strlen($line, 'UTF-8');

        if ($col < $lineLen) {
            // Delete the character at the cursor position.
            $before = mb_substr($line, 0, $col, 'UTF-8');
            $after = mb_substr($line, $col + 1, null, 'UTF-8');
            $lines[$this->state->cursorLine] = $before.$after;

            $this->state = $this->state->withLines($lines, $this->state->cursorLine, $col);

            return;
        }

        if ($this->state->cursorLine < \count($lines) - 1) {
            // Merge the next line into the current line.
            $nextLine = $lines[$this->state->cursorLine + 1];
            $merged = $line.$nextLine;

            array_splice($lines, $this->state->cursorLine + 1, 1);
            $lines[$this->state->cursorLine] = $merged;

            $this->state = $this->state->withLines($lines, $this->state->cursorLine, $col);
        }
    }

    /**
     * Insert a newline, splitting the current line at the cursor.
     *
     * Everything after the cursor moves to a new line and the cursor
     * is placed at column 0 of that new line.
     */
    public function insertNewline(): void
    {
        $lines = $this->state->lines;
        $line = $lines[$this->state->cursorLine];
        $col = $this->state->cursorColumn;

        $left = mb_substr($line, 0, $col, 'UTF-8');
        $right = mb_substr($line, $col, null, 'UTF-8');

        $lines[$this->state->cursorLine] = $left;
        array_splice($lines, $this->state->cursorLine + 1, 0, [$right]);

        $this->state = $this->state->withLines($lines, $this->state->cursorLine + 1, 0);
    }

    // ─── Cursor movement ────────────────────────────────────────

    /**
     * Move the cursor left by one character.
     *
     * When at column 0, wraps to the end of the previous line (if any).
     */
    public function moveCursorLeft(): void
    {
        $col = $this->state->cursorColumn;

        if ($col > 0) {
            $this->state = $this->state->withCursor($this->state->cursorLine, $col - 1);

            return;
        }

        if ($this->state->cursorLine > 0) {
            $prevLineLen = mb_strlen($this->state->lines[$this->state->cursorLine - 1], 'UTF-8');
            $this->state = $this->state->withCursor($this->state->cursorLine - 1, $prevLineLen);
        }
    }

    /**
     * Move the cursor right by one character.
     *
     * When at the end of a line, wraps to the start of the next line (if any).
     */
    public function moveCursorRight(): void
    {
        $lineLen = mb_strlen($this->state->lines[$this->state->cursorLine], 'UTF-8');

        if ($this->state->cursorColumn < $lineLen) {
            $this->state = $this->state->withCursor($this->state->cursorLine, $this->state->cursorColumn + 1);

            return;
        }

        if ($this->state->cursorLine < \count($this->state->lines) - 1) {
            $this->state = $this->state->withCursor($this->state->cursorLine + 1, 0);
        }
    }

    /**
     * Move the cursor to the previous logical line, clamping the column.
     */
    public function moveCursorUp(): void
    {
        if ($this->state->cursorLine > 0) {
            $newLine = $this->state->cursorLine - 1;
            $lineLen = mb_strlen($this->state->lines[$newLine], 'UTF-8');
            $newCol = min($this->state->cursorColumn, $lineLen);
            $this->state = $this->state->withCursor($newLine, $newCol);
        }
    }

    /**
     * Move the cursor to the next logical line, clamping the column.
     */
    public function moveCursorDown(): void
    {
        if ($this->state->cursorLine < \count($this->state->lines) - 1) {
            $newLine = $this->state->cursorLine + 1;
            $lineLen = mb_strlen($this->state->lines[$newLine], 'UTF-8');
            $newCol = min($this->state->cursorColumn, $lineLen);
            $this->state = $this->state->withCursor($newLine, $newCol);
        }
    }

    /**
     * Move the cursor to the start of the current line (Home).
     */
    public function moveCursorToLineStart(): void
    {
        $this->state = $this->state->withCursor($this->state->cursorLine, 0);
    }

    /**
     * Move the cursor to the end of the current line (End).
     */
    public function moveCursorToLineEnd(): void
    {
        $lineLen = mb_strlen($this->state->lines[$this->state->cursorLine], 'UTF-8');
        $this->state = $this->state->withCursor($this->state->cursorLine, $lineLen);
    }

    // ─── Lifecycle ───────────────────────────────────────────────

    /**
     * Reset the editor to an empty state.
     */
    public function clear(): void
    {
        $this->state = EditorState::fromText('');
    }

    /**
     * True when the editor contains only a single empty line.
     */
    public function isEmpty(): bool
    {
        return 1 === \count($this->state->lines)
            && '' === $this->state->lines[0];
    }

    /**
     * Return the current text and reset to empty.
     */
    public function submit(): string
    {
        $text = $this->getText();
        $this->clear();

        return $text;
    }

    // ─── Read-only accessors for rendering ───────────────────────

    public function getCursorLine(): int
    {
        return $this->state->cursorLine;
    }

    public function getCursorColumn(): int
    {
        return $this->state->cursorColumn;
    }

    public function getLineCount(): int
    {
        return \count($this->state->lines);
    }

    public function getLine(int $line): string
    {
        if ($line < 0 || $line >= \count($this->state->lines)) {
            throw new \OutOfBoundsException(\sprintf('Line %d out of bounds [0, %d).', $line, \count($this->state->lines)));
        }

        return $this->state->lines[$line];
    }
}
