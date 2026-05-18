<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Editor;

use Ineersa\Tui\Editor\PromptEditor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromptEditor::class)]
final class PromptEditorTextEditingTest extends TestCase
{
    // ─── Construction / text access ──────────────────────────────

    #[Test]
    public function defaultConstructorIsEmpty(): void
    {
        $editor = new PromptEditor();

        self::assertTrue($editor->isEmpty());
        self::assertSame('', $editor->getText());
        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function constructorWithText(): void
    {
        $editor = new PromptEditor('hello');

        self::assertFalse($editor->isEmpty());
        self::assertSame('hello', $editor->getText());
        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function getTextJoinsLines(): void
    {
        $editor = new PromptEditor("line1\nline2\nline3");

        self::assertSame("line1\nline2\nline3", $editor->getText());
        self::assertSame(3, $editor->getLineCount());
    }

    #[Test]
    public function getLineReturnsCorrectLine(): void
    {
        $editor = new PromptEditor("a\nb\nc");

        self::assertSame('a', $editor->getLine(0));
        self::assertSame('b', $editor->getLine(1));
        self::assertSame('c', $editor->getLine(2));
    }

    // ─── setText ─────────────────────────────────────────────────

    #[Test]
    public function setTextResetsState(): void
    {
        $editor = new PromptEditor('old');
        $editor->setText('new text');

        self::assertSame('new text', $editor->getText());
        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function setTextEmptyString(): void
    {
        $editor = new PromptEditor('content');
        $editor->setText('');

        self::assertTrue($editor->isEmpty());
        self::assertSame('', $editor->getText());
    }

    // ─── insertText ──────────────────────────────────────────────

    #[Test]
    public function insertTextIntoEmpty(): void
    {
        $editor = new PromptEditor();
        $editor->insertText('hello');

        self::assertSame('hello', $editor->getText());
        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(5, $editor->getCursorColumn());
    }

    #[Test]
    public function insertTextAtCursor(): void
    {
        $editor = new PromptEditor('hello world');
        // Move cursor to after the space: "hello |world" (col 6)
        $editor->moveCursorToLineEnd();
        for ($i = 0; $i < 5; ++$i) {
            $editor->moveCursorLeft();
        }
        // Cursor is now after "hello " (col 6)
        $editor->insertText('beautiful ');

        self::assertSame('hello beautiful world', $editor->getText());
    }

    #[Test]
    public function insertTextWithNewlines(): void
    {
        $editor = new PromptEditor('abc');
        $editor->moveCursorRight(); // col 1, after 'a'
        $editor->insertText("x\ny\nz");

        self::assertSame("ax\ny\nzbc", $editor->getText());
        self::assertSame(2, $editor->getCursorLine());
        self::assertSame(1, $editor->getCursorColumn()); // after 'z'
    }

    #[Test]
    public function insertTextEmptyStringDoesNothing(): void
    {
        $editor = new PromptEditor('hello');
        $editor->insertText('');

        self::assertSame('hello', $editor->getText());
        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function insertTextAtEndOfLine(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorToLineEnd();
        $editor->insertText(' world');

        self::assertSame('hello world', $editor->getText());
        self::assertSame(11, $editor->getCursorColumn());
    }

    #[Test]
    public function insertTextOnlyNewlines(): void
    {
        $editor = new PromptEditor('ab');
        $editor->moveCursorRight(); // after 'a'
        $editor->insertText("\n\n");

        // "a\n\nb" — three lines with empty middle
        self::assertSame("a\n\nb", $editor->getText());
        self::assertSame(3, $editor->getLineCount());
        self::assertSame(2, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    // ─── deleteBackward ──────────────────────────────────────────

    #[Test]
    public function deleteBackwardRemovesCharacter(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorToLineEnd();
        $editor->deleteBackward();

        self::assertSame('hell', $editor->getText());
        self::assertSame(4, $editor->getCursorColumn());
    }

    #[Test]
    public function deleteBackwardAtStartOfLineMergesWithPrevious(): void
    {
        $editor = new PromptEditor("line1\nline2");
        // Move to start of line 2
        $editor->moveCursorDown();
        $editor->moveCursorDown(); // now on line 2? let me think...

        // Actually, constructor starts at (0,0). moveCursorDown once = line 1.
        // moveCursorDown again? line 1 is last line (since we have 2 lines).
        // So we'd be on line 1. Let me reset.
        $editor = new PromptEditor("line1\nline2");
        // Move cursor to start of line 2
        $editor->moveCursorDown();
        // Now line=1, col=0
        $editor->deleteBackward();

        self::assertSame('line1line2', $editor->getText());
        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(5, $editor->getCursorColumn()); // at end of 'line1'
    }

    #[Test]
    public function deleteBackwardAtStartOfFirstLineDoesNothing(): void
    {
        $editor = new PromptEditor('hello');
        $editor->deleteBackward();

        self::assertSame('hello', $editor->getText());
        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function deleteBackwardHandlesMultiByte(): void
    {
        $editor = new PromptEditor('café');
        $editor->moveCursorToLineEnd();
        $editor->deleteBackward();

        self::assertSame('caf', $editor->getText());
        self::assertSame(3, $editor->getCursorColumn());
    }

    // ─── deleteForward ───────────────────────────────────────────

    #[Test]
    public function deleteForwardRemovesCharacter(): void
    {
        $editor = new PromptEditor('hello');
        $editor->deleteForward();

        self::assertSame('ello', $editor->getText());
        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function deleteForwardAtEndOfLineMergesWithNext(): void
    {
        $editor = new PromptEditor("line1\nline2");
        $editor->moveCursorToLineEnd(); // col 5, line 0
        $editor->deleteForward();

        self::assertSame('line1line2', $editor->getText());
        self::assertSame(1, $editor->getLineCount());
        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(5, $editor->getCursorColumn());
    }

    #[Test]
    public function deleteForwardAtEndOfLastLineDoesNothing(): void
    {
        $editor = new PromptEditor("line1\nline2");
        // Move to end of last line
        $editor->moveCursorDown();
        $editor->moveCursorToLineEnd();
        $editor->deleteForward();

        self::assertSame("line1\nline2", $editor->getText());
        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(5, $editor->getCursorColumn());
    }

    #[Test]
    public function deleteForwardHandlesMultiByte(): void
    {
        $editor = new PromptEditor('café');
        $editor->deleteForward(); // delete 'c'

        self::assertSame('afé', $editor->getText());
        self::assertSame(0, $editor->getCursorColumn());
    }

    // ─── insertNewline ───────────────────────────────────────────

    #[Test]
    public function insertNewlineSplitsLine(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorRight();
        $editor->moveCursorRight(); // col 2
        $editor->insertNewline();

        self::assertSame("he\nllo", $editor->getText());
        self::assertSame(2, $editor->getLineCount());
        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function insertNewlineAtStartOfLine(): void
    {
        $editor = new PromptEditor('hello');
        $editor->insertNewline();

        self::assertSame("\nhello", $editor->getText());
        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function insertNewlineAtEndOfLine(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorToLineEnd();
        $editor->insertNewline();

        self::assertSame("hello\n", $editor->getText());
        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    // ─── clear / isEmpty / submit ─────────────────────────────────

    #[Test]
    public function clearResetsToEmpty(): void
    {
        $editor = new PromptEditor("multi\nline");
        $editor->clear();

        self::assertTrue($editor->isEmpty());
        self::assertSame('', $editor->getText());
        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function isEmptyReturnsTrueForDefault(): void
    {
        $editor = new PromptEditor();

        self::assertTrue($editor->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseForContent(): void
    {
        $editor = new PromptEditor('x');

        self::assertFalse($editor->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseForMultiLineEmptyLines(): void
    {
        // Two empty lines means getText() returns "\n" — not empty.
        $editor = new PromptEditor("\n");

        self::assertFalse($editor->isEmpty());
    }

    #[Test]
    public function submitReturnsTextAndClears(): void
    {
        $editor = new PromptEditor('submit me');
        $text = $editor->submit();

        self::assertSame('submit me', $text);
        self::assertTrue($editor->isEmpty());
        self::assertSame('', $editor->getText());
    }

    #[Test]
    public function submitOnEmptyReturnsEmptyString(): void
    {
        $editor = new PromptEditor();
        $text = $editor->submit();

        self::assertSame('', $text);
        self::assertTrue($editor->isEmpty());
    }

    // ─── Read-only accessors ─────────────────────────────────────

    #[Test]
    public function getLineCount(): void
    {
        $editor = new PromptEditor("a\nb\nc");

        self::assertSame(3, $editor->getLineCount());
    }

    #[Test]
    public function getLineCountEmpty(): void
    {
        $editor = new PromptEditor();

        self::assertSame(1, $editor->getLineCount());
    }

    #[Test]
    public function getLineThrowsForOutOfBounds(): void
    {
        $editor = new PromptEditor('hello');

        $this->expectException(\OutOfBoundsException::class);
        $editor->getLine(1);
    }
}
