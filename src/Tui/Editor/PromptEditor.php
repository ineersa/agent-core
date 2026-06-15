<?php

declare(strict_types=1);

namespace Ineersa\Tui\Editor;

use Ineersa\Tui\Completion\CompletionSuggestion;
use Symfony\Component\String\UnicodeString;
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
     * Accept a completion suggestion by replacing the full editor
     * text and placing the cursor at the end of the inserted text.
     *
     * Uses the same clear-and-reinsert pattern as
     * {@see replaceText()} — {@see EditorWidget::setText('')} then
     * {@see EditorWidget::handleInput()} — but wraps multi-line
     * replacement text in bracketed paste markers so the editor's
     * {@see EditorDocument::handlePaste()} preserves newline
     * structure and places the cursor at the end of the last line.
     *
     * Single-line replacement text goes through the normal
     * character-input path which advances the cursor past every
     * typed character.
     *
     * The replacement range MUST be a suffix ending at the editor
     * cursor — this is always true for all current completion
     * providers (slash, @ file mention, session ID) which assume
     * cursor-at-end.  If the range is not a suffix, trailing text
     * is carried over.
     */
    public function acceptCompletion(CompletionSuggestion $suggestion): void
    {
        $current = $this->widget->getText();
        $currentLen = \strlen($current);

        // Build the new text: everything before the replacement range
        // plus the inserted suggestion text.  Use Symfony UnicodeString
        // for UTF-8 safe substring operations — replacement offsets are
        // byte-level but we want grapheme-aware slicing.
        $prefix = (new UnicodeString($current))
            ->slice(0, $suggestion->replacementStart)
            ->toString();
        $newText = $prefix.$suggestion->insertText;

        // For non-suffix ranges (should not happen with current
        // providers), carry over any trailing text that was not
        // part of the replaced range.
        $replacementEnd = $suggestion->replacementStart
            + $suggestion->replacementLength;

        if ($replacementEnd < $currentLen) {
            $trailing = (new UnicodeString($current))
                ->slice($replacementEnd)
                ->toString();
            $newText .= $trailing;
        }

        // Clear the editor; cursor lands at (0,0).
        $this->widget->setText('');

        if (str_contains($newText, "\n")) {
            // Multi-line: wrap in bracketed paste so the editor's
            // handlePaste() preserves newlines and places the cursor
            // at the end of the last inserted line.
            $this->widget->handleInput(
                "\x1b[200~".$newText."\x1b[201~",
            );
        } else {
            // Single-line: normal character-input path advances the
            // cursor past every typed character.
            $this->widget->handleInput($newText);
        }
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
     * Prefer {@see acceptCompletion()} for completion acceptance —
     * it preserves multi-line content and respects the editor's
     * undo/line-structure invariants.  This method remains for
     * full-editor replacement callers (e.g. single-line command
     * insertion at replacementStart 0).
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
