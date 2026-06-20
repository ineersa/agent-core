<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Editor;

use Ineersa\Tui\Editor\EditorState;
use Ineersa\Tui\Editor\PromptEditor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromptEditor::class)]
final class PromptEditorTest extends TestCase
{
    private PromptEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new PromptEditor();
    }

    // ─── Construction ────────────────────────────────────────────

    #[Test]
    public function defaultsToEmpty(): void
    {
        self::assertTrue($this->editor->isEmpty());
        self::assertSame('', $this->editor->getText());
    }

    #[Test]
    public function getWidgetReturnsEditorWidget(): void
    {
        $widget = $this->editor->getWidget();

        self::assertInstanceOf(\Symfony\Component\Tui\Widget\EditorWidget::class, $widget);
        self::assertSame('', $widget->getText());
    }

    // ─── Configuration ───────────────────────────────────────────

    #[Test]
    public function configurationIsFluent(): void
    {
        $result = $this->editor->setMinVisibleLines(1)->setMaxVisibleLines(20);

        self::assertSame($this->editor, $result);
    }

    // ─── setText / getText ──────────────────────────────────────

    #[Test]
    public function setAndGetText(): void
    {
        $this->editor->setText('hello');

        self::assertSame('hello', $this->editor->getText());
        self::assertFalse($this->editor->isEmpty());
    }

    #[Test]
    public function setTextMultiline(): void
    {
        $this->editor->setText("line1\nline2\nline3");

        self::assertSame("line1\nline2\nline3", $this->editor->getText());
    }

    #[Test]
    public function setTextEmptyString(): void
    {
        $this->editor->setText('content');
        $this->editor->setText('');

        self::assertTrue($this->editor->isEmpty());
        self::assertSame('', $this->editor->getText());
    }

    #[Test]
    public function setTextOverwritesWidget(): void
    {
        $this->editor->setText('first');
        self::assertSame('first', $this->editor->getWidget()->getText());

        $this->editor->setText('second');
        self::assertSame('second', $this->editor->getWidget()->getText());
    }

    // ─── clear ──────────────────────────────────────────────────

    #[Test]
    public function clearResetsToEmpty(): void
    {
        $this->editor->setText("multi\nline");
        $this->editor->clear();

        self::assertTrue($this->editor->isEmpty());
        self::assertSame('', $this->editor->getText());
    }

    // ─── isEmpty ────────────────────────────────────────────────

    #[Test]
    public function isEmptyTrueByDefault(): void
    {
        self::assertTrue($this->editor->isEmpty());
    }

    #[Test]
    public function isEmptyFalseWithContent(): void
    {
        $this->editor->setText('x');

        self::assertFalse($this->editor->isEmpty());
    }

    #[Test]
    public function isEmptyTrueAfterClear(): void
    {
        $this->editor->setText('content');
        $this->editor->clear();

        self::assertTrue($this->editor->isEmpty());
    }

    // ─── extract ─────────────────────────────────────────────────

    #[Test]
    public function extractReturnsTextAndClears(): void
    {
        $this->editor->setText('submit me');
        $text = $this->editor->extract();

        self::assertSame('submit me', $text);
        self::assertTrue($this->editor->isEmpty());
        self::assertSame('', $this->editor->getText());
    }

    #[Test]
    public function extractOnEmptyReturnsEmptyString(): void
    {
        $text = $this->editor->extract();

        self::assertSame('', $text);
        self::assertTrue($this->editor->isEmpty());
    }

    #[Test]
    public function extractMultiline(): void
    {
        $this->editor->setText("line1\nline2");
        $text = $this->editor->extract();

        self::assertSame("line1\nline2", $text);
        self::assertSame('', $this->editor->getText());
    }

    // ─── getState ────────────────────────────────────────────────

    #[Test]
    public function getStateReturnsCorrectSnapshot(): void
    {
        $this->editor->setText('hello');
        $state = $this->editor->getState();

        self::assertSame('hello', $state->getText());
    }

    #[Test]
    public function getStateOnEmpty(): void
    {
        $state = $this->editor->getState();

        self::assertTrue($state->isEmpty());
        self::assertSame([''], $state->getLines());
    }

    #[Test]
    public function getStateMultiline(): void
    {
        $this->editor->setText("a\nb\nc");
        $state = $this->editor->getState();

        self::assertSame(['a', 'b', 'c'], $state->getLines());
        self::assertSame("a\nb\nc", $state->getText());
    }

    #[Test]
    public function getStateIsImmutableViaEditorState(): void
    {
        $this->editor->setText('hello');
        $state = $this->editor->getState();

        // EditorState is readonly — this is a snapshot, not a reference
        self::assertInstanceOf(EditorState::class, $state);
        self::assertSame('hello', $state->getText());

        // Modifying the editor does not affect the snapshot
        $this->editor->setText('world');
        self::assertSame('hello', $state->getText());
    }

    // ─── getWidget ───────────────────────────────────────────────

    #[Test]
    public function getWidgetReturnsConsistentInstance(): void
    {
        $w1 = $this->editor->getWidget();
        $w2 = $this->editor->getWidget();

        self::assertSame($w1, $w2);
    }

    #[Test]
    public function widgetTextReadsBackAfterSet(): void
    {
        $this->editor->setText('via prompt editor');

        // Same text visible through both PromptEditor and raw widget
        self::assertSame('via prompt editor', $this->editor->getText());
        self::assertSame('via prompt editor', $this->editor->getWidget()->getText());
    }

    // ─── typeText ────────────────────────────────────────────────

    #[Test]
    public function typeTextSingleLine(): void
    {
        $this->editor->typeText('/help');

        self::assertSame('/help', $this->editor->getText());
    }

    #[Test]
    public function typeTextMultiline(): void
    {
        $this->editor->typeText("Hello\n\n@");

        self::assertSame("Hello\n\n@", $this->editor->getText());
    }

    // ─── acceptCompletion ────────────────────────────────────────

    #[Test]
    public function acceptCompletionReplacesSuffix(): void
    {
        // Use typeText so the cursor is at end — this matches the
        // real TUI flow where the user types text character-by-character
        // before triggering completion.
        $this->editor->typeText('/he');

        $this->editor->acceptCompletion(
            replacementStart: 0,
            replacementLength: 3,
            insertText: '/help ',
        );

        self::assertSame('/help ', $this->editor->getText());
    }

    #[Test]
    public function typingAfterSlashAcceptanceGoesAfterCommand(): void
    {
        // Accept a slash completion, then type additional arguments.
        // The cursor must land after the inserted command so that
        // further typing appends naturally.
        $this->editor->typeText('/he');

        $this->editor->acceptCompletion(
            replacementStart: 0,
            replacementLength: 3,
            insertText: '/help ',
        );

        // Simulate the user typing more text after acceptance.
        $this->editor->getWidget()->handleInput('f');
        $this->editor->getWidget()->handleInput('o');
        $this->editor->getWidget()->handleInput('o');

        self::assertSame('/help foo', $this->editor->getText());
    }

    #[Test]
    public function acceptCompletionPreservesMultilinePrefix(): void
    {
        // Reproduces GitHub issue #123: multiline @ completion clears editor.
        $this->editor->typeText("Hello\n\n@");

        $this->editor->acceptCompletion(
            replacementStart: 7,
            replacementLength: 1,
            insertText: '@src/file.php ',
        );

        self::assertSame("Hello\n\n@src/file.php ", $this->editor->getText());
        self::assertStringContainsString('Hello', $this->editor->getText());
        self::assertStringContainsString('@src/file.php', $this->editor->getText());
    }

    #[Test]
    public function acceptCompletionHandlesEmptySuffix(): void
    {
        // replacementLength=0 means nothing to delete, only insert.
        $this->editor->typeText('/rename ');

        $this->editor->acceptCompletion(
            replacementStart: 8,
            replacementLength: 0,
            insertText: '42 ',
        );

        self::assertSame('/rename 42 ', $this->editor->getText());
    }

    #[Test]
    public function acceptCompletionMultiByteSuffix(): void
    {
        // Suffix containing a multi-byte emoji.
        // Must use typeText so cursor is at end and the grapheme-aware
        // Backspace correctly deletes the emoji (4 bytes, 1 grapheme).
        $this->editor->typeText('/he😀');

        $this->editor->acceptCompletion(
            replacementStart: 0,
            replacementLength: 7,
            insertText: '/help ',
        );

        self::assertSame('/help ', $this->editor->getText());
    }

    #[Test]
    public function acceptCompletionCarriesTrailingTextForNonSuffix(): void
    {
        // Non-suffix replacement: carry over text after replaced range.
        // Must use typeText so cursor is at end.
        $this->editor->typeText('abc_def');

        $this->editor->acceptCompletion(
            replacementStart: 3,
            replacementLength: 1,
            insertText: 'X',
        );

        // "abc" + "X" + "def"
        self::assertSame('abcXdef', $this->editor->getText());
    }
}
