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
        $state = new EditorState(['hello']);

        $this->assertSame(['hello'], $state->getLines());
    }

    #[Test]
    public function emptyLinesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Lines must not be empty');

        new EditorState([]);
    }

    #[Test]
    public function getLinesReturnsCopy(): void
    {
        $state = new EditorState(['a', 'b']);
        $lines = $state->getLines();

        // getLines() returns the array; readonly class prevents
        // reassignment but not element mutation. This test documents
        // that callers receive the internal array.
        $this->assertSame(['a', 'b'], $lines);
    }

    #[Test]
    public function fromTextEmptyString(): void
    {
        $state = EditorState::fromText('');

        $this->assertSame([''], $state->getLines());
    }

    #[Test]
    public function fromTextSingleLine(): void
    {
        $state = EditorState::fromText('hello world');

        $this->assertSame(['hello world'], $state->getLines());
    }

    #[Test]
    public function fromTextMultiLine(): void
    {
        $state = EditorState::fromText("line1\nline2\nline3");

        $this->assertSame(['line1', 'line2', 'line3'], $state->getLines());
    }

    #[Test]
    public function fromTextNormalizesCarriageReturnLineFeed(): void
    {
        $state = EditorState::fromText("line1\r\nline2");

        $this->assertSame(['line1', 'line2'], $state->getLines());
    }

    #[Test]
    public function fromTextNormalizesCarriageReturn(): void
    {
        $state = EditorState::fromText("line1\rline2");

        $this->assertSame(['line1', 'line2'], $state->getLines());
    }

    #[Test]
    public function fromTextNormalizesMixedLineEndings(): void
    {
        $state = EditorState::fromText("a\r\nb\rc\nd");

        $this->assertSame(['a', 'b', 'c', 'd'], $state->getLines());
    }

    #[Test]
    public function fromTextWithTrailingNewlinePreserved(): void
    {
        // Consistent with EditorDocument::setText() behavior:
        // explode("\n", "hello\n") → ["hello", ""]
        $state = EditorState::fromText("hello\n");

        $this->assertSame(['hello', ''], $state->getLines());
    }

    #[Test]
    public function emptyCreatesEmptyState(): void
    {
        $state = EditorState::empty();

        $this->assertSame([''], $state->getLines());
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
    public function onlyNewlineResultsInTwoEmptyLines(): void
    {
        // Single "\n" → explode yields ['', ''] after normalization.
        // This matches EditorDocument::setText("\n") behavior.
        $state = EditorState::fromText("\n");

        $this->assertSame(['', ''], $state->getLines());
        $this->assertFalse($state->isEmpty());
    }
}
