<?php

declare(strict_types=1);

namespace Ineersa\Tui\Editor;

use Symfony\Component\Tui\Widget\Util\StringUtils;

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
     * Applies the same normalization pipeline as
     * EditorDocument::setText():
     *  1. Sanitize invalid UTF-8 byte sequences
     *  2. Normalize line endings (\r\n, \r → \n)
     *  3. Strip control bytes (keeping TAB and LF)
     *  4. Split on \n
     */
    public static function fromText(string $text): self
    {
        // Match EditorDocument::setText order: sanitizeUtf8 → CRLF/CR → stripControlBytes.
        $text = StringUtils::sanitizeUtf8($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = StringUtils::stripControlBytes($text);

        $lines = '' === $text ? [''] : explode("\n", $text);

        return new self($lines);
    }

    
    
    /**
     * Return the full text by joining logical lines with \n.
     */
    public function getText(): string
    {
        return implode("\n", $this->lines);
    }

    }
