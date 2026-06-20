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

        self::assertSame(['hello'], $state->getLines());
    }

    #[Test]
    public function emptyLinesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Lines must not be empty');

        new EditorState([]);
    }

    #[Test]
    public function getLinesReturnsExternalCopy(): void
    {
        $state = new EditorState(['a', 'b']);
        $lines = $state->getLines();

        // PHP 8.5 readonly class properties are deeply immutable: direct
        // element mutation ($state->lines[0] = ..) throws Error.  The
        // getter returns by value, and arrays are COW-separated on write,
        // so callers receive an independent snapshot.
        $lines[0] = 'mutated';

        self::assertSame(['a', 'b'], $state->getLines());
        self::assertSame(['mutated', 'b'], $lines);
    }

    #[Test]
    public function fromTextEmptyString(): void
    {
        $state = EditorState::fromText('');

        self::assertSame([''], $state->getLines());
    }

    #[Test]
    public function fromTextSingleLine(): void
    {
        $state = EditorState::fromText('hello world');

        self::assertSame(['hello world'], $state->getLines());
    }

    #[Test]
    public function fromTextMultiLine(): void
    {
        $state = EditorState::fromText("line1\nline2\nline3");

        self::assertSame(['line1', 'line2', 'line3'], $state->getLines());
    }

    #[Test]
    public function fromTextNormalizesCarriageReturnLineFeed(): void
    {
        $state = EditorState::fromText("line1\r\nline2");

        self::assertSame(['line1', 'line2'], $state->getLines());
    }

    #[Test]
    public function fromTextNormalizesCarriageReturn(): void
    {
        $state = EditorState::fromText("line1\rline2");

        self::assertSame(['line1', 'line2'], $state->getLines());
    }

    #[Test]
    public function fromTextNormalizesMixedLineEndings(): void
    {
        $state = EditorState::fromText("a\r\nb\rc\nd");

        self::assertSame(['a', 'b', 'c', 'd'], $state->getLines());
    }

    #[Test]
    public function fromTextWithTrailingNewlinePreserved(): void
    {
        // Consistent with EditorDocument::setText() behavior:
        // explode("\n", "hello\n") → ["hello", ""]
        $state = EditorState::fromText("hello\n");

        self::assertSame(['hello', ''], $state->getLines());
    }

    #[Test]
    public function emptyCreatesEmptyState(): void
    {
        $state = EditorState::empty();

        self::assertSame([''], $state->getLines());
    }

    #[Test]
    public function getTextJoinsLines(): void
    {
        $state = EditorState::fromText("a\nb\nc");

        self::assertSame("a\nb\nc", $state->getText());
    }

    #[Test]
    public function getTextOnEmpty(): void
    {
        $state = EditorState::empty();

        self::assertSame('', $state->getText());
    }

    #[Test]
    public function isEmptyTrueForSingleEmptyLine(): void
    {
        $state = EditorState::empty();

        self::assertTrue($state->isEmpty());
    }

    #[Test]
    public function isEmptyTrueForFromTextEmpty(): void
    {
        $state = EditorState::fromText('');

        self::assertTrue($state->isEmpty());
    }

    #[Test]
    public function isEmptyFalseForContent(): void
    {
        $state = EditorState::fromText('hello');

        self::assertFalse($state->isEmpty());
    }

    #[Test]
    public function isEmptyFalseForTrailingNewline(): void
    {
        // Two lines with second empty → not "empty" per isEmpty()
        $state = EditorState::fromText("hello\n");

        self::assertFalse($state->isEmpty());
    }

    #[Test]
    public function onlyNewlineResultsInTwoEmptyLines(): void
    {
        // Single "\n" → explode yields ['', ''] after normalization.
        // This matches EditorDocument::setText("\n") behavior.
        $state = EditorState::fromText("\n");

        self::assertSame(['', ''], $state->getLines());
        self::assertFalse($state->isEmpty());
    }

    // ─── Control byte stripping (matching EditorDocument::setText) ──

    #[Test]
    public function fromTextStripsControlBytes(): void
    {
        // BEL (\x07) and other C0 controls are stripped
        $state = EditorState::fromText("hello\x07world");

        self::assertSame(['helloworld'], $state->getLines());
    }

    #[Test]
    public function fromTextStripsDelCharacter(): void
    {
        $state = EditorState::fromText("hi\x7fthere");

        self::assertSame(['hithere'], $state->getLines());
    }

    #[Test]
    public function fromTextPreservesTabAndNewline(): void
    {
        // TAB (\x09) and LF (\x0a) are preserved
        $state = EditorState::fromText("col1\tcol2\nline2");

        self::assertSame(["col1\tcol2", 'line2'], $state->getLines());
    }

    #[Test]
    public function fromTextStripsC1ControlBytes(): void
    {
        // C1 controls encoded as UTF-8 \xc2[\x80-\x9f]
        $state = EditorState::fromText("hello\xc2\x80world");

        self::assertSame(['helloworld'], $state->getLines());
    }

    #[Test]
    public function fromTextSanitizesInvalidUtf8(): void
    {
        // Invalid UTF-8 byte sequences are stripped via iconv
        $state = EditorState::fromText("valid\xFE\xFFtext");

        self::assertStringContainsString('valid', $state->getText());
        self::assertStringContainsString('text', $state->getText());
    }

    #[Test]
    public function fromTextEmptyAfterAllStripping(): void
    {
        // If all content is control bytes, result is an empty state
        $state = EditorState::fromText("\x00\x01");

        self::assertTrue($state->isEmpty());
        self::assertSame([''], $state->getLines());
    }
}
