<?php

declare(strict_types=1);

namespace Ineersa\Tui\Editor;

use Symfony\Component\Tui\Widget\EditorWidget;

/**
 * Hatfield prompt editor facade.
 *
 * Wraps a Symfony TUI {@see EditorWidget}, delegating all text buffer
 * and cursor operations to it.  This class adds Hatfield-specific
 * lifecycle operations (extract, snapshot state) and is designed as
 * a Symfony DI service.
 *
 * Text mutation and key dispatch are handled entirely by EditorWidget /
 * EditorDocument — we do not reimplement them.
 */
final class PromptEditor
{
    public function __construct(
        private readonly EditorWidget $widget,
    ) {
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
