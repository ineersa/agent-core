<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptGlyphs;
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
    public function testEmptyThinkingShowsFallbackText(): void
    {
        // When thinking is visible but text is empty, displayTextFor()
        // returns '[thinking]' as a content fallback.
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'th',
                kind: TranscriptBlockKindEnum::AssistantThinking,
                runId: self::SESSION_ID,
                seq: 1,
                text: '',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringContainsString('[thinking]', $text, 'Empty thinking fallback missing');
        self::assertStringContainsString('⋯', $text, 'Thinking glyph missing');
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
            new TranscriptBlock(
                id: 'ap1', kind: TranscriptBlockKindEnum::Approval,
                runId: self::SESSION_ID, seq: 6, text: 'approved',
            ),
            new TranscriptBlock(
                id: 'q1', kind: TranscriptBlockKindEnum::Question,
                runId: self::SESSION_ID, seq: 7, text: 'ask?',
            ),
            new TranscriptBlock(
                id: 'c1', kind: TranscriptBlockKindEnum::Cancelled,
                runId: self::SESSION_ID, seq: 8, text: 'cancelled',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        // Strip ANSI codes for pure text matching
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $text);

        self::assertStringContainsString(TranscriptGlyphs::GLYPH_USER_MESSAGE, $plain);
        self::assertStringContainsString(TranscriptGlyphs::GLYPH_ASSISTANT_MESSAGE, $plain);
        self::assertStringContainsString(TranscriptGlyphs::GLYPH_ASSISTANT_THINKING, $plain);
        self::assertStringContainsString(TranscriptGlyphs::GLYPH_TOOL, $plain);
        self::assertStringContainsString(TranscriptGlyphs::GLYPH_PROGRESS, $plain);
        self::assertStringContainsString(TranscriptGlyphs::GLYPH_APPROVAL, $plain);
        self::assertStringContainsString(TranscriptGlyphs::GLYPH_QUESTION, $plain);
        self::assertStringContainsString(TranscriptGlyphs::GLYPH_CANCELLED, $plain);
    }

    #[Test]
    public function testStreamingBlockShowsSuffix(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'str',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'partial',
                streaming: true,
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringContainsString('partial', $text, 'Streaming block text missing');
        self::assertStringContainsString('...', $text, 'Streaming suffix missing');
    }

    #[Test]
    public function testApprovalBlockShowsGlyph(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'ap',
                kind: TranscriptBlockKindEnum::Approval,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'Approve?',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringContainsString('🔐', $text, 'Approval glyph missing');
        self::assertStringContainsString('Approve?', $text, 'Approval text missing');
    }

    #[Test]
    public function testQuestionBlockShowsGlyph(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'q',
                kind: TranscriptBlockKindEnum::Question,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'What?',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringContainsString('?', $text, 'Question glyph missing');
        self::assertStringContainsString('What?', $text, 'Question text missing');
    }

    #[Test]
    public function testCancelledBlockShowsGlyph(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'cn',
                kind: TranscriptBlockKindEnum::Cancelled,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'aborted',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringContainsString('✕', $text, 'Cancelled glyph missing');
        self::assertStringContainsString('aborted', $text, 'Cancelled text missing');
    }

    /* ───── RENDER-03: Markdown and thinking behavior ───── */

    #[Test]
    public function testHiddenThinkingShowsPlaceholderNotContent(): void
    {
        // When thinking visible=false, raw content must NOT appear.
        // Only the placeholder "  ⋯ Thinking" should render.
        $config = new TranscriptDisplayConfig(thinkingVisible: false);
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            displayConfig: $config,
        );
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'th',
                kind: TranscriptBlockKindEnum::AssistantThinking,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'this contains **private** reasoning data',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        // The placeholder glyph and text must appear
        self::assertStringContainsString('⋯', $text, 'Thinking glyph missing for hidden block');
        self::assertStringContainsString('Thinking', $text, 'Thinking placeholder text missing');

        // Raw content must NOT appear
        self::assertStringNotContainsString('private reasoning', $text);
    }

    #[Test]
    public function testCollapsedFlagDoesNotHideThinkingWhenConfigVisible(): void
    {
        // Thinking visibility is driven by TranscriptDisplayConfig, NOT
        // by TranscriptBlock::collapsed. With default config (visible=true),
        // a collapsed block must still show its content.
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'th',
                kind: TranscriptBlockKindEnum::AssistantThinking,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'collapsed but visible',
                collapsed: true,
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringContainsString('collapsed but visible', $text,
            'Collapsed thinking must show content when config says visible',
        );
    }

    #[Test]
    public function testCollapsedFlagDoesNotRevealThinkingWhenConfigHidden(): void
    {
        // Even with collapsed=true, if config says hidden, content is hidden.
        $config = new TranscriptDisplayConfig(thinkingVisible: false);
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            displayConfig: $config,
        );
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'th',
                kind: TranscriptBlockKindEnum::AssistantThinking,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'secret',
                collapsed: true,
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        self::assertStringNotContainsString('secret', $text,
            'Collapsed thinking must NOT show content when config says hidden',
        );
        // Placeholder must still appear
        self::assertStringContainsString('⋯', $text);
        self::assertStringContainsString('Thinking', $text);
    }

    #[Test]
    public function testAssistantMessageRendersBoldMarkdown(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'a1',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'Use **bold** for emphasis',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        // Markdown bold should be rendered (not shown as literal **bold**)
        self::assertStringContainsString('bold', $text);
        self::assertStringNotContainsString('**bold**', $text,
            'Markdown bold delimiters must not appear literally',
        );
        self::assertStringContainsString('◇', $text, 'Assistant glyph missing');
    }

    #[Test]
    public function testUserMessageRendersCodeInlineMarkdown(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'u1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'Run `bin/console` to start',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        // Inline code should be rendered (backticks consumed)
        self::assertStringContainsString('bin/console', $text);
        self::assertStringNotContainsString('`bin/console`', $text,
            'Inline code backticks must not appear literally',
        );
        self::assertStringContainsString('❯', $text, 'User glyph missing');
    }

    #[Test]
    public function testVisibleThinkingRendersMarkdownContent(): void
    {
        // Default config (thinkingVisible=true) renders content through MarkdownWidget
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'th',
                kind: TranscriptBlockKindEnum::AssistantThinking,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'reasoning with *italic* and `code`',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        // Markdown rendered, raw delimiters consumed
        self::assertStringContainsString('reasoning with', $text);
        self::assertStringContainsString('italic', $text);
        self::assertStringContainsString('code', $text);
        self::assertStringNotContainsString('*italic*', $text,
            'Italic delimiters must not appear literally',
        );
        self::assertStringNotContainsString('`code`', $text,
            'Code backticks must not appear literally',
        );
        self::assertStringContainsString('⋯', $text, 'Thinking glyph missing');
    }

    /* ───── RENDER-03 review-iterate: Markdown element theme colours ───── */

    #[Test]
    public function testHeadingSgrAppearsInAssistantMessage(): void
    {
        // The glyph prefix (◇) precedes the first line. Block-level markdown
        // must appear on subsequent lines to be parsed by CommonMark.
        // This text embeds a heading on the second paragraph.
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'a1',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: self::SESSION_ID,
                seq: 1,
                text: "Here is a section\n\n# Section Title\n\nBody text.",
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $ansi = $harness->ansiOutput();

        // bright_cyan foreground = 96 (MarkdownHeading in default virtual palette)
        self::assertStringContainsString("\x1b[96m", $ansi,
            'Markdown heading must use bright_cyan theme colour (SGR 96)',
        );
    }

    #[Test]
    public function testInlineCodeSgrAppearsInAssistantMessage(): void
    {
        // Inline code should use the MarkdownCode colour (bright_green → \x1b[92m).
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'a1',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'Use `$this->code` inline',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $ansi = $harness->ansiOutput();

        // bright_green foreground = 92
        self::assertStringContainsString("\x1b[92m", $ansi,
            'Inline code must use bright_green theme colour (SGR 92)',
        );
    }

    #[Test]
    public function testLinkSgrAppearsInAssistantMessage(): void
    {
        // Link text should use the MarkdownLink colour (bright_blue → \x1b[94m).
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'a1',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'Visit [example](https://example.com)',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $ansi = $harness->ansiOutput();

        // bright_blue foreground = 94
        self::assertStringContainsString("\x1b[94m", $ansi,
            'Markdown link must use bright_blue theme colour (SGR 94)',
        );

        // bright_black foreground (MarkdownLinkUrl) = 90
        self::assertStringContainsString("\x1b[90m", $ansi,
            'Markdown link URL must use bright_black theme colour (SGR 90)',
        );
    }

    #[Test]
    public function testListBulletSgrAppearsInAssistantMessage(): void
    {
        // The glyph prefix (◇) precedes the first line, so the first list
        // marker needs to be on a separate line after an introductory sentence.
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'a1',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: self::SESSION_ID,
                seq: 1,
                text: "Items:\n\n- first item\n- second item",
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $ansi = $harness->ansiOutput();

        // bright_blue foreground = 94 (MarkdownListBullet in default virtual palette)
        self::assertStringContainsString("\x1b[94m", $ansi,
            'List bullet must use bright_blue theme colour (SGR 94)',
        );
    }

}
