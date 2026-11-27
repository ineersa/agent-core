<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\CopyCommandHandler;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Utility\Clipboard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CopyCommandHandlerTest extends TestCase
{
    #[Test]
    public function copiesLastAssistantMessageText(): void
    {
        $state = new TuiSessionState('test-session');
        $state->transcript = [
            $this->buildBlock('b1', TranscriptBlockKindEnum::UserMessage, 1, 'Hello'),
            $this->buildBlock('b2', TranscriptBlockKindEnum::AssistantMessage, 2, 'Hi there!'),
            $this->buildBlock('b3', TranscriptBlockKindEnum::UserMessage, 3, 'How are you?'),
            $this->buildBlock('b4', TranscriptBlockKindEnum::AssistantMessage, 4, 'I am fine, thanks.'),
        ];

        $clipboard = $this->createMock(Clipboard::class);
        $clipboard->expects($this->once())->method('copy')->with('I am fine, thanks.')->willReturn(true);

        $handler = new CopyCommandHandler($state, $clipboard);
        $result = $handler->handle(new SlashCommand('copy', '', '/copy'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Copied last model output to clipboard.', $result->text);
        $this->assertSame('system', $result->role);
        $this->assertSame('', $result->style);
    }

    #[Test]
    public function returnsNothingToCopyWhenTranscriptEmpty(): void
    {
        $state = new TuiSessionState('test-session');
        $state->transcript = [];

        $clipboard = $this->createMock(Clipboard::class);
        $clipboard->expects($this->never())->method('copy');

        $handler = new CopyCommandHandler($state, $clipboard);
        $result = $handler->handle(new SlashCommand('copy', '', '/copy'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Nothing to copy — no model output yet.', $result->text);
        $this->assertSame('system', $result->role);
        $this->assertSame('muted', $result->style);
    }

    #[Test]
    public function returnsNothingToCopyWhenNoAssistantMessages(): void
    {
        $state = new TuiSessionState('test-session');
        $state->transcript = [
            $this->buildBlock('b1', TranscriptBlockKindEnum::UserMessage, 1, 'Hello'),
            $this->buildBlock('b2', TranscriptBlockKindEnum::System, 2, 'System note'),
            $this->buildBlock('b3', TranscriptBlockKindEnum::ToolResult, 3, 'Tool output'),
        ];

        $clipboard = $this->createMock(Clipboard::class);
        $clipboard->expects($this->never())->method('copy');

        $handler = new CopyCommandHandler($state, $clipboard);
        $result = $handler->handle(new SlashCommand('copy', '', '/copy'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Nothing to copy — no model output yet.', $result->text);
        $this->assertSame('muted', $result->style);
    }

    #[Test]
    public function picksLastAssistantMessageWhenMultipleKindsPresent(): void
    {
        $state = new TuiSessionState('test-session');
        $state->transcript = [
            $this->buildBlock('b1', TranscriptBlockKindEnum::UserMessage, 1, 'User msg 1'),
            $this->buildBlock('b2', TranscriptBlockKindEnum::AssistantMessage, 2, 'First assistant'),
            $this->buildBlock('b3', TranscriptBlockKindEnum::UserMessage, 3, 'User msg 2'),
            $this->buildBlock('b4', TranscriptBlockKindEnum::AssistantThinking, 4, 'Thinking...'),
            $this->buildBlock('b5', TranscriptBlockKindEnum::ToolResult, 5, 'Tool result'),
            $this->buildBlock('b6', TranscriptBlockKindEnum::AssistantMessage, 6, 'Second assistant'),
            $this->buildBlock('b7', TranscriptBlockKindEnum::UserMessage, 7, 'User msg 3'),
            $this->buildBlock('b8', TranscriptBlockKindEnum::AssistantThinking, 8, 'More thinking...'),
            $this->buildBlock('b9', TranscriptBlockKindEnum::AssistantMessage, 9, 'Third assistant — this is the last'),
        ];

        $clipboard = $this->createMock(Clipboard::class);
        $clipboard->expects($this->once())->method('copy')->with('Third assistant — this is the last')->willReturn(true);

        $handler = new CopyCommandHandler($state, $clipboard);
        $result = $handler->handle(new SlashCommand('copy', '', '/copy'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Copied last model output to clipboard.', $result->text);
    }

    #[Test]
    public function copiesEmptyAssistantMessageText(): void
    {
        $state = new TuiSessionState('test-session');
        $state->transcript = [
            $this->buildBlock('b1', TranscriptBlockKindEnum::AssistantMessage, 1, ''),
        ];

        $clipboard = $this->createMock(Clipboard::class);
        $clipboard->expects($this->once())->method('copy')->with('')->willReturn(true);

        $handler = new CopyCommandHandler($state, $clipboard);
        $result = $handler->handle(new SlashCommand('copy', '', '/copy'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Copied last model output to clipboard.', $result->text);
        $this->assertSame('', $result->style);
    }

    #[Test]
    public function returnsFailureWhenCopyReturnsFalse(): void
    {
        $state = new TuiSessionState('test-session');
        $state->transcript = [
            $this->buildBlock('b1', TranscriptBlockKindEnum::AssistantMessage, 1, 'Some output'),
        ];

        $clipboard = $this->createMock(Clipboard::class);
        $clipboard->expects($this->once())->method('copy')->with('Some output')->willReturn(false);

        $handler = new CopyCommandHandler($state, $clipboard);
        $result = $handler->handle(new SlashCommand('copy', '', '/copy'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Failed to copy last model output to clipboard.', $result->text);
        $this->assertSame('system', $result->role);
        $this->assertSame('muted', $result->style);
    }

    private function buildBlock(string $id, TranscriptBlockKindEnum $kind, int $seq, string $text): TranscriptBlock
    {
        return new TranscriptBlock(
            id: $id,
            kind: $kind,
            runId: 'run-test',
            seq: $seq,
            text: $text,
        );
    }
}
