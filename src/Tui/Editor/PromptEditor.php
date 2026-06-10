<?php

declare(strict_types=1);

namespace Ineersa\Tui\Editor;

use Symfony\Component\Tui\Input\Keybindings;
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

    /**
     * Apply custom keybindings to the underlying EditorWidget.
     *
     * Widget-level keybindings override EditorWidget defaults for the
     * same action names. To add a keybinding without removing the
     * default keys for an action, include both the new and default
     * key identifiers in the binding list.
     *
     * @return $this
     */
    public function setKeybindings(Keybindings $keybindings): self
    {
        $this->widget->setKeybindings($keybindings);

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
     * Replace all editor text and leave the cursor at the end.
     *
     * Clears the editor, then inserts the replacement text through
     * the editor's normal character-input path.  Because EditorWidget
     * does not expose a public cursor-position API and the task policy
     * forbids private-property access (reflection, Closure::bind), we
     * avoid cursor management entirely: insertText() advances the
     * cursor past every typed character.
     *
     * This method is safe for printable single-line text without
     * terminal control characters — the current use case is
     * slash-completion acceptance at replacementStart 0 where the
     * insert text is a single-line ASCII command with trailing space.
     *
     * Open question: Symfony TUI has no public cursor setter on
     * EditorWidget, so any future feature needing arbitrary cursor
     * positioning after setText() will need the same constraint
     * awareness or a contribution upstream.
     */
    public function replaceText(string $text): void
    {
        // Clear puts the cursor at (0,0).
        $this->widget->setText('');

        // Regular text insertion advances cursor past every character.
        // EditorWidget::handleInput() delegates to
        // EditorDocument::insertText() which slides the cursor forward.
        $this->widget->handleInput($text);
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
