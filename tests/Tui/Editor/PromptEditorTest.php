<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Editor;

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
    public function getWidgetReturnsEditorWidget(): void
    {
        $widget = $this->editor->getWidget();

        $this->assertInstanceOf(\Symfony\Component\Tui\Widget\EditorWidget::class, $widget);
        $this->assertSame('', $widget->getText());
    }

    // ─── Configuration ───────────────────────────────────────────

    #[Test]
    public function configurationIsFluent(): void
    {
        $result = $this->editor->setMinVisibleLines(1)->setMaxVisibleLines(20);

        $this->assertSame($this->editor, $result);
    }

    // ─── setText / getText ──────────────────────────────────────

    #[Test]
    public function setTextMultiline(): void
    {
        $this->editor->setText("line1\nline2\nline3");

        $this->assertSame("line1\nline2\nline3", $this->editor->getText());
    }

    #[Test]
    public function setTextOverwritesWidget(): void
    {
        $this->editor->setText('first');
        $this->assertSame('first', $this->editor->getWidget()->getText());

        $this->editor->setText('second');
        $this->assertSame('second', $this->editor->getWidget()->getText());
    }

    // ─── clear ──────────────────────────────────────────────────

    #[Test]
    public function extractMultiline(): void
    {
        $this->editor->setText("line1\nline2");
        $text = $this->editor->extract();

        $this->assertSame("line1\nline2", $text);
        $this->assertSame('', $this->editor->getText());
    }

    // ─── getState ────────────────────────────────────────────────

    #[Test]
    public function getWidgetReturnsConsistentInstance(): void
    {
        $w1 = $this->editor->getWidget();
        $w2 = $this->editor->getWidget();

        $this->assertSame($w1, $w2);
    }

    #[Test]
    public function widgetTextReadsBackAfterSet(): void
    {
        $this->editor->setText('via prompt editor');

        // Same text visible through both PromptEditor and raw widget
        $this->assertSame('via prompt editor', $this->editor->getText());
        $this->assertSame('via prompt editor', $this->editor->getWidget()->getText());
    }

    // ─── typeText ────────────────────────────────────────────────

    #[Test]
    public function typeTextSingleLine(): void
    {
        $this->editor->typeText('/help');

        $this->assertSame('/help', $this->editor->getText());
    }

    #[Test]
    public function typeTextMultiline(): void
    {
        $this->editor->typeText("Hello\n\n@");

        $this->assertSame("Hello\n\n@", $this->editor->getText());
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

        $this->assertSame('/help ', $this->editor->getText());
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

        $this->assertSame('/help foo', $this->editor->getText());
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

        $this->assertSame("Hello\n\n@src/file.php ", $this->editor->getText());
        $this->assertStringContainsString('Hello', $this->editor->getText());
        $this->assertStringContainsString('@src/file.php', $this->editor->getText());
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

        $this->assertSame('/rename 42 ', $this->editor->getText());
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

        $this->assertSame('/help ', $this->editor->getText());
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
        $this->assertSame('abcXdef', $this->editor->getText());
    }

    #[Test]
    public function acceptCompletionUsesByteOffsetsWithNonAsciiPrefix(): void
    {
        // Replacement offsets are BYTE offsets into the raw editor text.
        // "grüße " is 8 bytes but only 6 code points — slicing by
        // grapheme/codepoint index would cut into the middle of the
        // multibyte run and leave a corrupted suffix.
        $this->editor->typeText('grüße /he');

        $this->editor->acceptCompletion(
            replacementStart: 8, // byte offset of "/he" after "grüße "
            replacementLength: 3,
            insertText: '/help',
        );

        $this->assertSame('grüße /help', $this->editor->getText());
    }

    #[Test]
    public function replaceTextPreservesMultilineContent(): void
    {
        // EditorWidget::handleInput() rejects control characters, so a
        // character-input insertion would silently drop the newline.
        $this->editor->replaceText("line1\nline2");

        $this->assertSame("line1\nline2", $this->editor->getText());

        // Cursor must be at the end: further typing appends after line2.
        $this->editor->getWidget()->handleInput('!');
        $this->assertSame("line1\nline2!", $this->editor->getText());
    }
}
