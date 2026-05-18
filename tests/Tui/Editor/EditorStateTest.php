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
        $state = new EditorState(['hello'], 0, 3, 0);

        $this->assertSame(['hello'], $state->lines);
        $this->assertSame(0, $state->cursorLine);
        $this->assertSame(3, $state->cursorColumn);
        $this->assertSame(0, $state->scrollOffset);
    }

    #[Test]
    public function cursorColumnAtEndOfLineIsValid(): void
    {
        $state = new EditorState(['ab'], 0, 2, 0);

        $this->assertSame(2, $state->cursorColumn);
    }

    #[Test]
    public function cursorColumnAtZeroIsValid(): void
    {
        $state = new EditorState(['ab'], 0, 0, 0);

        $this->assertSame(0, $state->cursorColumn);
    }

    #[Test]
    public function emptyLinesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Lines must not be empty');

        new EditorState([], 0, 0, 0);
    }

    #[Test]
    public function negativeCursorLineThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Cursor line -1 out of bounds');

        new EditorState(['hello'], -1, 0, 0);
    }

    #[Test]
    public function cursorLineTooLargeThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Cursor line 1 out of bounds [0, 1)');

        new EditorState(['hello'], 1, 0, 0);
    }

    #[Test]
    public function negativeCursorColumnThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Cursor column -1 out of bounds');

        new EditorState(['hello'], 0, -1, 0);
    }

    #[Test]
    public function cursorColumnTooLargeThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Cursor column 6 out of bounds [0, 5]');

        new EditorState(['hello'], 0, 6, 0);
    }

    #[Test]
    public function negativeScrollOffsetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Scroll offset must not be negative');

        new EditorState(['hello'], 0, 0, -1);
    }

    #[Test]
    public function fromTextEmptyString(): void
    {
        $state = EditorState::fromText('');

        $this->assertSame([''], $state->lines);
        $this->assertSame(0, $state->cursorLine);
        $this->assertSame(0, $state->cursorColumn);
        $this->assertSame(0, $state->scrollOffset);
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
    public function withLinesCreatesNewInstance(): void
    {
        $original = new EditorState(['original'], 0, 0, 0);
        $modified = $original->withLines(['modified'], 0, 5);

        // Original unchanged.
        $this->assertSame(['original'], $original->lines);
        $this->assertSame(0, $original->cursorColumn);

        // New instance carries changes.
        $this->assertSame(['modified'], $modified->lines);
        $this->assertSame(0, $modified->cursorLine);
        $this->assertSame(5, $modified->cursorColumn);
        $this->assertSame(0, $modified->scrollOffset);
    }

    #[Test]
    public function withCursorCreatesNewInstance(): void
    {
        $original = new EditorState(['hello'], 0, 0, 0);
        $modified = $original->withCursor(0, 5);

        $this->assertNotSame($original, $modified);
        $this->assertSame(['hello'], $modified->lines);
        $this->assertSame(0, $modified->cursorLine);
        $this->assertSame(5, $modified->cursorColumn);
    }

    #[Test]
    public function withScrollOffsetCreatesNewInstance(): void
    {
        $original = new EditorState(['hello'], 0, 0, 0);
        $modified = $original->withScrollOffset(10);

        $this->assertNotSame($original, $modified);
        $this->assertSame(['hello'], $modified->lines);
        $this->assertSame(0, $modified->cursorLine);
        $this->assertSame(0, $modified->cursorColumn);
        $this->assertSame(10, $modified->scrollOffset);
    }

    #[Test]
    public function handlesMultiByteCharactersInValidation(): void
    {
        // "héllo" is 5 chars but 'é' is 2 bytes — mb_strlen matters.
        $state = new EditorState(['héllo'], 0, 5, 0);

        $this->assertSame(5, $state->cursorColumn);
    }

    #[Test]
    public function cursorColumnTooLargeWithMultiByte(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Cursor column 6 out of bounds [0, 5]');

        new EditorState(['héllo'], 0, 6, 0);
    }
}
