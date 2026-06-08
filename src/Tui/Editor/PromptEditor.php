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
     * Symfony EditorWidget does not expose a public cursor API.
     * This adapter uses a tightly-scoped reflection access on the
     * private EditorDocument so the cursor lands after the inserted
     * command text rather than at line 0/col 0 (the setText default).
     *
     * Replace with a public cursor API if/when Symfony exposes one.
     * Keep the reflection internal to PromptEditor; callers must not
     * replicate this pattern.
     */
    public function setTextWithCursorAtEnd(string $text): void
    {
        $this->widget->setText($text);

        // Access the private EditorDocument so we can move the
        // cursor after the default setText reset.
        $docProp = (new \ReflectionClass($this->widget))->getProperty('document');
        /** @var \Symfony\Component\Tui\Widget\Editor\EditorDocument $document */
        $document = $docProp->getValue($this->widget);

        $lines = $document->getLines();
        $lastLine = \count($lines) - 1;
        $document->setCursorLine($lastLine);
        $document->setCursorCol(\strlen($lines[$lastLine]));
        $this->widget->invalidate();
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
