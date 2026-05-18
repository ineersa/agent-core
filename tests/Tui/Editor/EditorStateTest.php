<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Editor;

use Ineersa\Tui\Editor\EditorState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditorState::class)]
final class EditorStateTest extends TestCase
{
    #[Test]
    public function constructsWithValidData(): void
    {
        $state = new EditorState(['hello'], 0, 3);

        $this->assertSame(['hello'], $state->lines);
        $this->assertSame(0, $state->cursorLine);
        $this->assertSame(3, $state->cursorColumn);
    }

    #[Test]
    public function emptyLinesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Lines must not be empty');

        new EditorState([]);
    }

    #[Test]
    public function fromTextEmptyString(): void
    {
        $state = EditorState::fromText('');

        $this->assertSame([''], $state->lines);
        $this->assertSame(0, $state->cursorLine);
        $this->assertSame(0, $state->cursorColumn);
    }

    #[Test]
    public function fromTextSingleLine(): void
    {
        $state = EditorState::fromText('hello world');

        $this->assertSame(['hello world'], $state->lines);
        $this->assertSame(0, $state->cursorLine);
        $this->assertSame(0, $state->cursorColumn);
    }

    #[Test]
    public function fromTextMultiLine(): void
    {
        $state = EditorState::fromText("line1\nline2\nline3");

        $this->assertSame(['line1', 'line2', 'line3'], $state->lines);
        $this->assertSame(0, $state->cursorLine);
        $this->assertSame(0, $state->cursorColumn);
    }

    #[Test]
    public function fromTextWithTrailingNewlinePreserved(): void
    {
        // Consistent with EditorDocument::setText() behavior:
        // explode("\n", "hello\n") → ["hello", ""]
        $state = EditorState::fromText("hello\n");

        $this->assertSame(['hello', ''], $state->lines);
    }

    #[Test]
    public function emptyCreatesEmptyState(): void
    {
        $state = EditorState::empty();

        $this->assertSame([''], $state->lines);
        $this->assertSame(0, $state->cursorLine);
        $this->assertSame(0, $state->cursorColumn);
    }

    #[Test]
    public function getTextJoinsLines(): void
    {
        $state = EditorState::fromText("a\nb\nc");

        $this->assertSame("a\nb\nc", $state->getText());
    }

    #[Test]
    public function getTextOnEmpty(): void
    {
        $state = EditorState::empty();

        $this->assertSame('', $state->getText());
    }

    #[Test]
    public function isEmptyTrueForSingleEmptyLine(): void
    {
        $state = EditorState::empty();

        $this->assertTrue($state->isEmpty());
    }

    #[Test]
    public function isEmptyTrueForFromTextEmpty(): void
    {
        $state = EditorState::fromText('');

        $this->assertTrue($state->isEmpty());
    }

    #[Test]
    public function isEmptyFalseForContent(): void
    {
        $state = EditorState::fromText('hello');

        $this->assertFalse($state->isEmpty());
    }

    #[Test]
    public function isEmptyFalseForTrailingNewline(): void
    {
        // Two lines with second empty → not "empty" per isEmpty()
        $state = EditorState::fromText("hello\n");

        $this->assertFalse($state->isEmpty());
    }

    #[Test]
    public function cursorLineAndColumnDefaults(): void
    {
        $state = new EditorState(['a', 'b']);

        $this->assertSame(0, $state->cursorLine);
        $this->assertSame(0, $state->cursorColumn);
    }
}
