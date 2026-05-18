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

        $this->assertTrue($editor->isEmpty());
        $this->assertSame('', $editor->getText());
        $this->assertSame(0, $editor->getCursorLine());
        $this->assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function constructorWithText(): void
    {
        $editor = new PromptEditor('hello');

        $this->assertFalse($editor->isEmpty());
        $this->assertSame('hello', $editor->getText());
        $this->assertSame(0, $editor->getCursorLine());
        $this->assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function getTextJoinsLines(): void
    {
        $editor = new PromptEditor("line1\nline2\nline3");

        $this->assertSame("line1\nline2\nline3", $editor->getText());
        $this->assertSame(3, $editor->getLineCount());
    }

    #[Test]
    public function getLineReturnsCorrectLine(): void
    {
        $editor = new PromptEditor("a\nb\nc");

        $this->assertSame('a', $editor->getLine(0));
        $this->assertSame('b', $editor->getLine(1));
        $this->assertSame('c', $editor->getLine(2));
    }

    // ─── setText ─────────────────────────────────────────────────

    #[Test]
    public function setTextResetsState(): void
    {
        $editor = new PromptEditor('old');
        $editor->setText('new text');

        $this->assertSame('new text', $editor->getText());
        $this->assertSame(0, $editor->getCursorLine());
        $this->assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function setTextEmptyString(): void
    {
        $editor = new PromptEditor('content');
        $editor->setText('');

        $this->assertTrue($editor->isEmpty());
        $this->assertSame('', $editor->getText());
    }

    // ─── insertText ──────────────────────────────────────────────

    #[Test]
    public function insertTextIntoEmpty(): void
    {
        $editor = new PromptEditor();
        $editor->insertText('hello');

        $this->assertSame('hello', $editor->getText());
        $this->assertSame(0, $editor->getCursorLine());
        $this->assertSame(5, $editor->getCursorColumn());
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

        $this->assertSame('hello beautiful world', $editor->getText());
    }

    #[Test]
    public function insertTextWithNewlines(): void
    {
        $editor = new PromptEditor('abc');
        $editor->moveCursorRight(); // col 1, after 'a'
        $editor->insertText("x\ny\nz");

        $this->assertSame("ax\ny\nzbc", $editor->getText());
        $this->assertSame(2, $editor->getCursorLine());
        $this->assertSame(1, $editor->getCursorColumn()); // after 'z'
    }

    #[Test]
    public function insertTextEmptyStringDoesNothing(): void
    {
        $editor = new PromptEditor('hello');
        $editor->insertText('');

        $this->assertSame('hello', $editor->getText());
        $this->assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function insertTextAtEndOfLine(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorToLineEnd();
        $editor->insertText(' world');

        $this->assertSame('hello world', $editor->getText());
        $this->assertSame(11, $editor->getCursorColumn());
    }

    #[Test]
    public function insertTextOnlyNewlines(): void
    {
        $editor = new PromptEditor('ab');
        $editor->moveCursorRight(); // after 'a'
        $editor->insertText("\n\n");

        // "a\n\nb" — three lines with empty middle
        $this->assertSame("a\n\nb", $editor->getText());
        $this->assertSame(3, $editor->getLineCount());
        $this->assertSame(2, $editor->getCursorLine());
        $this->assertSame(0, $editor->getCursorColumn());
    }

    // ─── deleteBackward ──────────────────────────────────────────

    #[Test]
    public function deleteBackwardRemovesCharacter(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorToLineEnd();
        $editor->deleteBackward();

        $this->assertSame('hell', $editor->getText());
        $this->assertSame(4, $editor->getCursorColumn());
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

        $this->assertSame('line1line2', $editor->getText());
        $this->assertSame(0, $editor->getCursorLine());
        $this->assertSame(5, $editor->getCursorColumn()); // at end of 'line1'
    }

    #[Test]
    public function deleteBackwardAtStartOfFirstLineDoesNothing(): void
    {
        $editor = new PromptEditor('hello');
        $editor->deleteBackward();

        $this->assertSame('hello', $editor->getText());
        $this->assertSame(0, $editor->getCursorLine());
        $this->assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function deleteBackwardHandlesMultiByte(): void
    {
        $editor = new PromptEditor('café');
        $editor->moveCursorToLineEnd();
        $editor->deleteBackward();

        $this->assertSame('caf', $editor->getText());
        $this->assertSame(3, $editor->getCursorColumn());
    }

    // ─── deleteForward ───────────────────────────────────────────

    #[Test]
    public function deleteForwardRemovesCharacter(): void
    {
        $editor = new PromptEditor('hello');
        $editor->deleteForward();

        $this->assertSame('ello', $editor->getText());
        $this->assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function deleteForwardAtEndOfLineMergesWithNext(): void
    {
        $editor = new PromptEditor("line1\nline2");
        $editor->moveCursorToLineEnd(); // col 5, line 0
        $editor->deleteForward();

        $this->assertSame('line1line2', $editor->getText());
        $this->assertSame(1, $editor->getLineCount());
        $this->assertSame(0, $editor->getCursorLine());
        $this->assertSame(5, $editor->getCursorColumn());
    }

    #[Test]
    public function deleteForwardAtEndOfLastLineDoesNothing(): void
    {
        $editor = new PromptEditor("line1\nline2");
        // Move to end of last line
        $editor->moveCursorDown();
        $editor->moveCursorToLineEnd();
        $editor->deleteForward();

        $this->assertSame("line1\nline2", $editor->getText());
        $this->assertSame(1, $editor->getCursorLine());
        $this->assertSame(5, $editor->getCursorColumn());
    }

    #[Test]
    public function deleteForwardHandlesMultiByte(): void
    {
        $editor = new PromptEditor('café');
        $editor->deleteForward(); // delete 'c'

        $this->assertSame('afé', $editor->getText());
        $this->assertSame(0, $editor->getCursorColumn());
    }

    // ─── insertNewline ───────────────────────────────────────────

    #[Test]
    public function insertNewlineSplitsLine(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorRight();
        $editor->moveCursorRight(); // col 2
        $editor->insertNewline();

        $this->assertSame("he\nllo", $editor->getText());
        $this->assertSame(2, $editor->getLineCount());
        $this->assertSame(1, $editor->getCursorLine());
        $this->assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function insertNewlineAtStartOfLine(): void
    {
        $editor = new PromptEditor('hello');
        $editor->insertNewline();

        $this->assertSame("\nhello", $editor->getText());
        $this->assertSame(1, $editor->getCursorLine());
        $this->assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function insertNewlineAtEndOfLine(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorToLineEnd();
        $editor->insertNewline();

        $this->assertSame("hello\n", $editor->getText());
        $this->assertSame(1, $editor->getCursorLine());
        $this->assertSame(0, $editor->getCursorColumn());
    }

    // ─── clear / isEmpty / submit ─────────────────────────────────

    #[Test]
    public function clearResetsToEmpty(): void
    {
        $editor = new PromptEditor("multi\nline");
        $editor->clear();

        $this->assertTrue($editor->isEmpty());
        $this->assertSame('', $editor->getText());
        $this->assertSame(0, $editor->getCursorLine());
        $this->assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function isEmptyReturnsTrueForDefault(): void
    {
        $editor = new PromptEditor();

        $this->assertTrue($editor->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseForContent(): void
    {
        $editor = new PromptEditor('x');

        $this->assertFalse($editor->isEmpty());
    }

    #[Test]
    public function isEmptyReturnsFalseForMultiLineEmptyLines(): void
    {
        // Two empty lines means getText() returns "\n" — not empty.
        $editor = new PromptEditor("\n");

        $this->assertFalse($editor->isEmpty());
    }

    #[Test]
    public function submitReturnsTextAndClears(): void
    {
        $editor = new PromptEditor('submit me');
        $text = $editor->submit();

        $this->assertSame('submit me', $text);
        $this->assertTrue($editor->isEmpty());
        $this->assertSame('', $editor->getText());
    }

    #[Test]
    public function submitOnEmptyReturnsEmptyString(): void
    {
        $editor = new PromptEditor();
        $text = $editor->submit();

        $this->assertSame('', $text);
        $this->assertTrue($editor->isEmpty());
    }

    // ─── Read-only accessors ─────────────────────────────────────

    #[Test]
    public function getLineCount(): void
    {
        $editor = new PromptEditor("a\nb\nc");

        $this->assertSame(3, $editor->getLineCount());
    }

    #[Test]
    public function getLineCountEmpty(): void
    {
        $editor = new PromptEditor();

        $this->assertSame(1, $editor->getLineCount());
    }

    #[Test]
    public function getLineThrowsForOutOfBounds(): void
    {
        $editor = new PromptEditor('hello');

        $this->expectException(\OutOfBoundsException::class);
        $editor->getLine(1);
    }
}
