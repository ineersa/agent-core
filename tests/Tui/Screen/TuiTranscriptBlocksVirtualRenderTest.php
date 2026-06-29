<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Ineersa\Tui\Transcript\TranscriptBlockWidgetFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Virtual proof that {@see ChatScreen} → {@see LiveTextWidget} →
 * {@see TranscriptBlockWidget} renders transcript blocks through the
 * Symfony TUI widget-tree renderer with correct glyph/prefix language.
 *
 * Test thesis: when ChatScreen receives transcript blocks of normal kinds
 * via setTranscriptBlocks(), the rendered screen output contains preserved
 * glyph/prefix characters and blocks appear in insertion order. This
 * exercises the real widget -> render -> ScreenBuffer pipeline without tmux.
 */
final class TuiTranscriptBlocksVirtualRenderTest extends TestCase
{
    private const string SESSION_ID = 'virtual-transcript-render';

    #[Test]
    public function testSingleUserMessageShowsGlyphAndText(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'msg-1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'Hello world',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringContainsString('❯', $text, 'User message glyph missing');
        self::assertStringContainsString('Hello world', $text, 'User message text missing');
    }

    #[Test]
    public function testAssistantResponseShowsGlyphAndText(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'a-1',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'Here is the answer',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringContainsString('◇', $text, 'Assistant message glyph missing');
        self::assertStringContainsString('Here is the answer', $text, 'Assistant message text missing');
    }

    #[Test]
    public function testMultipleBlocksRenderInOrder(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'u1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'first prompt',
            ),
            new TranscriptBlock(
                id: 'a1',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: self::SESSION_ID,
                seq: 2,
                text: 'first response',
            ),
            new TranscriptBlock(
                id: 'u2',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: self::SESSION_ID,
                seq: 3,
                text: 'second prompt',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        // Verify all glyphs are present
        self::assertStringContainsString('❯', $text, 'User message glyph missing');
        self::assertStringContainsString('◇', $text, 'Assistant message glyph missing');

        // Verify ordering: first message comes before second
        $posFirst = strpos($text, 'first prompt');
        $posSecond = strpos($text, 'first response');
        $posThird = strpos($text, 'second prompt');

        self::assertNotFalse($posFirst, 'First block text not found');
        self::assertNotFalse($posSecond, 'Second block text not found');
        self::assertNotFalse($posThird, 'Third block text not found');
        self::assertLessThan($posSecond, $posFirst, 'Blocks out of order: first should appear before second');
        self::assertLessThan($posThird, $posSecond, 'Blocks out of order: second should appear before third');
    }

    #[Test]
    public function testToolCallAndResultShowToolGlyph(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: '',
                meta: ['tool_name' => 'read'],
            ),
            new TranscriptBlock(
                id: 'tr',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 2,
                text: '3 lines read',
                meta: ['tool_name' => 'read'],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringContainsString('●', $text, 'Tool glyph missing');
        self::assertStringContainsString('read', $text, 'Tool name missing');
        self::assertStringContainsString('3 lines read', $text, 'Tool result text missing');
    }

    #[Test]
    public function testThinkingBlockShowsThinkingGlyph(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'th',
                kind: TranscriptBlockKindEnum::AssistantThinking,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'reasoning step 1',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringContainsString('⋯', $text, 'Thinking glyph missing');
        self::assertStringContainsString('reasoning step 1', $text, 'Thinking text missing');
    }

    #[Test]
    public function testErrorBlockShowsErrorGlyph(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'err',
                kind: TranscriptBlockKindEnum::Error,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'something went wrong',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringContainsString('✕', $text, 'Error glyph missing');
        self::assertStringContainsString('something went wrong', $text, 'Error text missing');
    }

    #[Test]
    public function testThinkingHiddenWhenEmptyAndCollapsed(): void
    {
        // The old renderer showed "⋯ Thinking" for empty thinking.
        // The new widget-tree renderer should preserve this placeholder.
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'th',
                kind: TranscriptBlockKindEnum::AssistantThinking,
                runId: self::SESSION_ID,
                seq: 1,
                text: '',
                collapsed: true,
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        // The widget factory falls through to displayTextFor() which returns '[thinking]' for empty thinking
        self::assertStringContainsString('[thinking]', $text, 'Empty thinking placeholder missing');
        self::assertStringContainsString('⋯', $text, 'Thinking glyph missing for collapsed block');
    }

    #[Test]
    public function testSystemBlockRendersSeverityGlyph(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'sys',
                kind: TranscriptBlockKindEnum::System,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'info note',
                meta: ['severity' => 'info'],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringContainsString('ℹ', $text, 'System info glyph missing');
        self::assertStringContainsString('info note', $text, 'System text missing');
    }

    #[Test]
    public function testAllProvidedGlyphConstantsMatchRenderedOutput(): void
    {
        // Integration proof that named glyph constants correspond to
        // actual terminal output. If a constant is changed intentionally
        // it must be updated here too.
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'u1', kind: TranscriptBlockKindEnum::UserMessage,
                runId: self::SESSION_ID, seq: 1, text: 'user',
            ),
            new TranscriptBlock(
                id: 'a1', kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: self::SESSION_ID, seq: 2, text: 'assistant',
            ),
            new TranscriptBlock(
                id: 'th1', kind: TranscriptBlockKindEnum::AssistantThinking,
                runId: self::SESSION_ID, seq: 3, text: 'think',
            ),
            new TranscriptBlock(
                id: 'tc1', kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID, seq: 4, text: '', meta: ['tool_name' => 'tool'],
            ),
            new TranscriptBlock(
                id: 'pr1', kind: TranscriptBlockKindEnum::Progress,
                runId: self::SESSION_ID, seq: 5, text: 'doing',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        // Strip ANSI codes for pure text matching
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $text);

        self::assertStringContainsString(TranscriptBlockWidgetFactory::GLYPH_USER_MESSAGE, $plain);
        self::assertStringContainsString(TranscriptBlockWidgetFactory::GLYPH_ASSISTANT_MESSAGE, $plain);
        self::assertStringContainsString(TranscriptBlockWidgetFactory::GLYPH_ASSISTANT_THINKING, $plain);
        self::assertStringContainsString(TranscriptBlockWidgetFactory::GLYPH_TOOL, $plain);
        self::assertStringContainsString(TranscriptBlockWidgetFactory::GLYPH_PROGRESS, $plain);
    }
}
