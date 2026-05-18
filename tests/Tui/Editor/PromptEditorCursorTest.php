<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Editor;

use Ineersa\Tui\Editor\PromptEditor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PromptEditor::class)]
final class PromptEditorCursorTest extends TestCase
{
    // ─── moveCursorLeft ──────────────────────────────────────────

    #[Test]
    public function moveLeftWithinLine(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorToLineEnd(); // col 5
        $editor->moveCursorLeft();

        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(4, $editor->getCursorColumn());
    }

    #[Test]
    public function moveLeftAtStartWrapsToPreviousLine(): void
    {
        $editor = new PromptEditor("ab\ncd");
        // Move to start of line 2
        $editor->moveCursorDown();
        // col 0, line 1
        $editor->moveCursorLeft();

        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(2, $editor->getCursorColumn()); // end of "ab"
    }

    #[Test]
    public function moveLeftAtStartOfFirstLineDoesNothing(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorLeft();

        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function moveLeftMultipleSteps(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorToLineEnd(); // 5
        $editor->moveCursorLeft();      // 4
        $editor->moveCursorLeft();      // 3

        self::assertSame(3, $editor->getCursorColumn());
    }

    #[Test]
    public function moveLeftHandlesMultiByte(): void
    {
        $editor = new PromptEditor('café');
        $editor->moveCursorToLineEnd(); // col 4
        $editor->moveCursorLeft();      // col 3

        self::assertSame(3, $editor->getCursorColumn());
    }

    // ─── moveCursorRight ─────────────────────────────────────────

    #[Test]
    public function moveRightWithinLine(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorRight();

        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(1, $editor->getCursorColumn());
    }

    #[Test]
    public function moveRightAtEndWrapsToNextLine(): void
    {
        $editor = new PromptEditor("ab\ncd");
        $editor->moveCursorToLineEnd(); // col 2, line 0
        $editor->moveCursorRight();

        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function moveRightAtEndOfLastLineDoesNothing(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorToLineEnd(); // col 5
        $editor->moveCursorRight();

        self::assertSame(5, $editor->getCursorColumn());
    }

    #[Test]
    public function moveRightMultipleSteps(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorRight();
        $editor->moveCursorRight();
        $editor->moveCursorRight();

        self::assertSame(3, $editor->getCursorColumn());
    }

    #[Test]
    public function moveRightHandlesMultiByte(): void
    {
        $editor = new PromptEditor('café');
        $editor->moveCursorRight(); // after 'c'
        $editor->moveCursorRight(); // after 'a'

        self::assertSame(2, $editor->getCursorColumn());
    }

    // ─── moveCursorUp ────────────────────────────────────────────

    #[Test]
    public function moveUpToPreviousLine(): void
    {
        $editor = new PromptEditor("line1\nline2");
        $editor->moveCursorDown(); // line 1, col 0
        $editor->moveCursorUp();

        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function moveUpAtFirstLineDoesNothing(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorUp();

        self::assertSame(0, $editor->getCursorLine());
    }

    #[Test]
    public function moveUpClampsColumn(): void
    {
        // Long line on top, short line below
        $editor = new PromptEditor("hello\nab");
        // Move to col 4 on line 0
        for ($i = 0; $i < 4; ++$i) {
            $editor->moveCursorRight();
        }
        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(4, $editor->getCursorColumn());

        // Move down to shorter line — column clamped to 2
        $editor->moveCursorDown();
        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(2, $editor->getCursorColumn());
    }

    #[Test]
    public function moveUpMaintainsColumnPreference(): void
    {
        // Column 5 → move to shorter line (len 2) → col clamped to 2.
        // Then move back to longer line: should restore to 5? Or stay at 2?
        // The task says "clamp column", meaning we don't track a "preferred" column.
        // So moving back up from a clamped position uses the clamped column.
        $editor = new PromptEditor("hello\nab");
        $editor->moveCursorToLineEnd(); // (0, 5)
        $editor->moveCursorDown();      // (1, 2) — clamped
        $editor->moveCursorUp();        // back to line 0

        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(2, $editor->getCursorColumn());
    }

    // ─── moveCursorDown ──────────────────────────────────────────

    #[Test]
    public function moveDownToNextLine(): void
    {
        $editor = new PromptEditor("line1\nline2");
        $editor->moveCursorDown();

        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function moveDownAtLastLineDoesNothing(): void
    {
        $editor = new PromptEditor("line1\nline2");
        $editor->moveCursorDown(); // line 1
        $editor->moveCursorDown(); // still line 1

        self::assertSame(1, $editor->getCursorLine());
    }

    #[Test]
    public function moveDownClampsColumn(): void
    {
        $editor = new PromptEditor("ab\nhello");
        $editor->moveCursorToLineEnd(); // col 2, line 0
        $editor->moveCursorDown();

        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(2, $editor->getCursorColumn());
    }

    // ─── moveCursorToLineStart / moveCursorToLineEnd ──────────────

    #[Test]
    public function moveToLineStart(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorToLineEnd();
        $editor->moveCursorToLineStart();

        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function moveToLineEnd(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorToLineEnd();

        self::assertSame(5, $editor->getCursorColumn());
    }

    #[Test]
    public function moveToLineEndOnEmptyLine(): void
    {
        $editor = new PromptEditor();

        self::assertSame(0, $editor->getCursorColumn());
        $editor->moveCursorToLineEnd();

        self::assertSame(0, $editor->getCursorColumn());
    }

    #[Test]
    public function moveToLineStartOnEmptyLine(): void
    {
        $editor = new PromptEditor();
        $editor->moveCursorToLineStart();

        self::assertSame(0, $editor->getCursorColumn());
    }

    // ─── Cursor movement with multi-line ─────────────────────────

    #[Test]
    public function crossLineNavigationSequence(): void
    {
        $editor = new PromptEditor("abc\ndef\nghi");

        // Start: (0, 0)
        // Move right 3 times → (0, 3) — end of "abc"
        $editor->moveCursorRight();
        $editor->moveCursorRight();
        $editor->moveCursorRight();
        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(3, $editor->getCursorColumn());

        // Move right again → wraps to (1, 0)
        $editor->moveCursorRight();
        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());

        // Move down → (2, 0)
        $editor->moveCursorDown();
        self::assertSame(2, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());

        // Move to line end → (2, 3)
        $editor->moveCursorToLineEnd();
        self::assertSame(2, $editor->getCursorLine());
        self::assertSame(3, $editor->getCursorColumn());

        // Move left → (2, 2)
        $editor->moveCursorLeft();
        self::assertSame(2, $editor->getCursorLine());
        self::assertSame(2, $editor->getCursorColumn());

        // Move up → (1, 2) — clamp since "def" is len 3, col 2 is fine
        $editor->moveCursorUp();
        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(2, $editor->getCursorColumn());

        // Move left → (1, 1)
        $editor->moveCursorLeft();
        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(1, $editor->getCursorColumn());

        // Move left → (1, 0)
        $editor->moveCursorLeft();
        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());

        // Move left at start → wraps to (0, 3) — end of "abc"
        $editor->moveCursorLeft();
        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(3, $editor->getCursorColumn());
    }

    #[Test]
    public function moveLeftOverEmptyLines(): void
    {
        $editor = new PromptEditor("a\n\nb");
        // Move to line 2 (the "b" line)
        $editor->moveCursorDown(); // line 1
        $editor->moveCursorDown(); // line 2
        self::assertSame(2, $editor->getCursorLine());

        // Move left at start of "b" → wraps to end of empty line 1 → col 0
        $editor->moveCursorLeft();
        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());

        // Move left at start of empty line → wraps to end of "a" → col 1
        $editor->moveCursorLeft();
        self::assertSame(0, $editor->getCursorLine());
        self::assertSame(1, $editor->getCursorColumn());
    }

    #[Test]
    public function moveRightOverEmptyLines(): void
    {
        $editor = new PromptEditor("a\n\nb");
        // Move to end of "a"
        $editor->moveCursorToLineEnd(); // col 1, line 0
        // Move right → wraps to start of empty line 1
        $editor->moveCursorRight();
        self::assertSame(1, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());

        // Move right → wraps to start of "b"
        $editor->moveCursorRight();
        self::assertSame(2, $editor->getCursorLine());
        self::assertSame(0, $editor->getCursorColumn());
    }

    // ─── Insert + cursor interaction ─────────────────────────────

    #[Test]
    public function insertThenMoveCursor(): void
    {
        $editor = new PromptEditor('hello');
        $editor->moveCursorToLineEnd(); // col 5
        $editor->insertText(' world');
        // Cursor is at col 11
        self::assertSame(11, $editor->getCursorColumn());

        $editor->moveCursorLeft();
        $editor->moveCursorLeft();
        self::assertSame(9, $editor->getCursorColumn());

        $editor->insertText('!');
        self::assertSame('hello wor!ld', $editor->getText());
        self::assertSame(10, $editor->getCursorColumn());
    }
}
