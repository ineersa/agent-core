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

    /**
     * Set editor text with cursor at (0,0).
     *
     * Prefer {@see typeText()} when the editor should behave as
     * if the user typed the content — it places the cursor at the
     * end of the text, which is the state assumed by completion
     * providers (cursor-at-end).
     */
    public function setText(string $text): void
    {
        $this->widget->setText($text);
    }

    /**
     * Set editor text with the cursor at the end — the state
     * assumed by completion providers (cursor-at-end).
     *
     * Uses {@see setText()} to set the content, then navigates
     * to the end of the text using normal keyboard operations
     * (DOWN for each line, then END).  No clearing, reinserting,
     * or bracketed paste is involved.
     */
    public function typeText(string $text): void
    {
        $this->widget->setText($text);

        // Navigate cursor to end of text.
        $newlineCount = substr_count($text, "\n");
        for ($i = 0; $i < $newlineCount; ++$i) {
            $this->widget->handleInput("\x1b[B");
        }
        $this->widget->handleInput("\x1b[F");
    }

    /**
     * Accept a completion suggestion using only normal editor
     * editing operations ({@see EditorWidget::handleInput()}).
     *
     * Does NOT clear the editor, does NOT use {@see setText()},
     * and does NOT use bracketed paste — this keeps the editor's
     * internal state (lines, cursor, undo/kill ring, viewport)
     * fully intact through the completion edit.
     *
     * How it works:
     * 1. Identify the suffix from {@see CompletionSuggestion::$replacementStart}
     *    to end-of-text — this is the text we must delete before
     *    inserting the suggestion.
     * 2. Delete that suffix one grapheme at a time by sending
     *    {@see EditorWidget::handleInput("\x7f")} (Backspace),
     *    matching the editor's grapheme-aware
     *    {@see EditorDocument::deleteCharBackward()} path.
     * 3. Insert the suggestion text via the normal
     *    character-input path.
     * 4. If the replacement range is not a suffix (carries trailing
     *    text), re-insert that trailing text after the suggestion.
     *
     * The replacement range SHOULD be a suffix ending at the editor
     * cursor — this is always true for all current completion
     * providers (slash, @ file mention, session ID) which assume
     * cursor-at-end.  Non-suffix ranges are handled correctly but
     * are not expected from current providers.
     *
     * Because we delete via the editor's normal Backspace path,
     * preceding multi-line content is preserved — fixing GitHub
     * issue #123 where "Hello\n\n@" → Tab cleared the editor.
     */
    public function acceptCompletion(CompletionSuggestion $suggestion): void
    {
        $current = $this->widget->getText();
        $currentLen = \strlen($current);

        // Extract the suffix we need to delete: everything from
        // replacementStart to the end of the current editor text.
        // We use Symfony UnicodeString for UTF-8 safe substring
        // operations — replacement offsets are byte-level.
        $suffixToDelete = (new UnicodeString($current))
            ->slice($suggestion->replacementStart)
            ->toString();

        // Delete the suffix one grapheme at a time through the
        // editor's normal Backspace path.  Symfony TUI's
        // EditorDocument::deleteCharBackward() is grapheme-aware
        // (uses grapheme_str_split), so one Backspace = one
        // grapheme — matching our count exactly.
        $graphemes = grapheme_str_split($suffixToDelete);
        for ($i = 0, $n = \count($graphemes); $i < $n; ++$i) {
            $this->widget->handleInput("\x7f");
        }

        // Build the text to insert: the suggestion plus any trailing
        // content that was not part of the replaced range.
        $replacementEnd = $suggestion->replacementStart
            + $suggestion->replacementLength;
        $insertText = $suggestion->insertText;

        if ($replacementEnd < $currentLen) {
            $trailing = (new UnicodeString($current))
                ->slice($replacementEnd)
                ->toString();
            $insertText .= $trailing;
        }

        // Insert via the normal character-input path.  The cursor
        // advances past every typed character, so the cursor lands
        // after the inserted text — ready for further typing.
        if ('' !== $insertText) {
            $this->widget->handleInput($insertText);
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
