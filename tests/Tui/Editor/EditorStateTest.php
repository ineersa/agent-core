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
    public function emptyLinesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Lines must not be empty');

        new EditorState([]);
    }

    #[Test]
    public function getTextJoinsLines(): void
    {
        $state = EditorState::fromText("a\nb\nc");

        $this->assertSame("a\nb\nc", $state->getText());
    }

    #[Test]
    public function fromTextSanitizesInvalidUtf8(): void
    {
        // Invalid UTF-8 byte sequences are stripped via iconv
        $state = EditorState::fromText("valid\xFE\xFFtext");

        $this->assertStringContainsString('valid', $state->getText());
        $this->assertStringContainsString('text', $state->getText());
    }

    #[Test]
