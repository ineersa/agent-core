<?php

declare(strict_types=1);

namespace Ineersa\Tui\Editor;

use Symfony\Component\Tui\Widget\EditorWidget;

/**
 * Interactive prompt editor facade (DI service).
 *
 * Wraps a Symfony TUI {@see EditorWidget} as the interactive text input.
 * ChatScreen wires this via DI; ChatLayout uses the separate
 * {@see PromptEditorWidget} for static rendering. Do not confuse the two.
 *
 * This class OWNS the EditorWidget (creates it internally) and exposes
 * Hatfield-specific lifecycle operations (extract, snapshot state).
 * Text mutation and key dispatch are delegated entirely to
 * EditorWidget / EditorDocument — we do not reimplement them.
 */
final class PromptEditor
{
    private readonly EditorWidget $widget;

    public function __construct()
    {
        $this->widget = new EditorWidget();
    }

    // ─── Configuration ────────────────────────────────────────────

    /**
     * @return $this
     */
    public function setMinVisibleLines(int $lines): self
    {
        $this->widget->setMinVisibleLines($lines);

        return $this;
    }

    /**
     * @return $this
     */
    public function setMaxVisibleLines(?int $lines): self
    {
        $this->widget->setMaxVisibleLines($lines);

        return $this;
    }

    // ─── Text access ────────────────────────────────────────────

    public function getText(): string
    {
        return $this->widget->getText();
    }

    public function setText(string $text): void
    {
        $this->widget->setText($text);
    }

    /**
     * Set editor text and reposition the cursor at the end.
     *
     * EditorWidget::setText() resets the cursor to (0,0).  This helper
     * moves the cursor to the end of the last line using only public
     * EditorWidget APIs: handleInput() with standard ANSI cursor-movement
     * escape sequences that EditorWidget's built-in keybindings dispatch.
     *
     * Strategy: setText to insert the content, then simulate DOWN arrow
     * for each line beyond the first, followed by END to hit the line end.
     * These are public EditorWidget::handleInput() calls — no reflection,
     * Closure::bind, or private-property access.
     */
    public function setTextWithCursorAtEnd(string $text): void
    {
        $this->widget->setText($text);

        // Move cursor down N-1 lines (cursor starts at line 0).
        $newlineCount = substr_count($text, "\n");
        for ($i = 0; $i < $newlineCount; ++$i) {
            $this->widget->handleInput("\x1b[B");
        }

        // Move to the end of the current line.
        $this->widget->handleInput("\x1b[F");
    }

    // ─── Lifecycle ───────────────────────────────────────────────

    /**
     * Clear the editor, resetting to empty text.
     */
    public function clear(): void
    {
        $this->widget->setText('');
    }

    /**
     * True when the editor contains no user-visible text.
     */
    public function isEmpty(): bool
    {
        return '' === $this->widget->getText();
    }

    /**
     * Extract the current text and clear the editor.
     *
     * Returns the text that was in the editor before clearing.
     */
    public function extract(): string
    {
        $text = $this->widget->getText();
        $this->widget->setText('');

        return $text;
    }

    // ─── Snapshot ────────────────────────────────────────────────

    /**
     * Take an immutable snapshot of the current editor state.
     *
     * Useful for test assertions and session persistence.
     */
    public function getState(): EditorState
    {
        return EditorState::fromText($this->widget->getText());
    }

    // ─── Widget access ───────────────────────────────────────────

    /**
     * Expose the underlying Symfony TUI widget for wiring into the
     * TUI layout and listener registration.
     *
     * Callers must not mutate text through this reference directly —
     * use the PromptEditor lifecycle methods for that.
     */
    public function getWidget(): EditorWidget
    {
        return $this->widget;
    }
}
