<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use Ineersa\Tui\Transcript\TranscriptGlyphs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Virtual product proof that {@see ChatScreen} → mounted
 * {@see \Ineersa\Tui\Transcript\TranscriptMountedWidget} renders transcript blocks
 * with correct glyph/prefix language through the live Symfony TUI tree.
 *
 * Test thesis: when ChatScreen receives transcript blocks of normal kinds
 * via setTranscriptBlocks(), the rendered screen output contains preserved
 * glyph/prefix characters and blocks appear in insertion order. This
 * exercises the real widget -> render -> ScreenBuffer pipeline without tmux.
 * Structural mounted-context / identity proofs live in
 * {@see TuiMountedTranscriptVirtualTest}.
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

        $this->assertStringContainsString('❯', $text, 'User message glyph missing');
        $this->assertStringContainsString('Hello world', $text, 'User message text missing');
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

        $this->assertStringContainsString('◇', $text, 'Assistant message glyph missing');
        $this->assertStringContainsString('Here is the answer', $text, 'Assistant message text missing');
    }

    #[Test]
    public function testTurnSeparatorAppearsBeforeLaterUserMessage(): void
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
        $separator = str_repeat(TranscriptGlyphs::TURN_SEPARATOR_CHAR, 80);

        $this->assertStringContainsString($separator, $text, 'User-turn separator missing before later user message');
        $this->assertLessThan(strpos($text, 'second prompt'), strpos($text, $separator), 'Separator should appear before the later user message');
    }

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
        $this->assertStringContainsString('❯', $text, 'User message glyph missing');
        $this->assertStringContainsString('◇', $text, 'Assistant message glyph missing');

        // Verify ordering: first message comes before second
        $posFirst = strpos($text, 'first prompt');
        $posSecond = strpos($text, 'first response');
        $posThird = strpos($text, 'second prompt');

        $this->assertNotFalse($posFirst, 'First block text not found');
        $this->assertNotFalse($posSecond, 'Second block text not found');
        $this->assertNotFalse($posThird, 'Third block text not found');
        $this->assertLessThan($posSecond, $posFirst, 'Blocks out of order: first should appear before second');
        $this->assertLessThan($posThird, $posSecond, 'Blocks out of order: second should appear before third');
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

        $this->assertStringContainsString('●', $text, 'Tool glyph missing');
        $this->assertStringContainsString('read', $text, 'Tool name missing');
        $this->assertStringContainsString('3 lines read', $text, 'Tool result text missing');
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

        $this->assertStringContainsString('⋯', $text, 'Thinking glyph missing');
        $this->assertStringContainsString('reasoning step 1', $text, 'Thinking text missing');
    }

    #[Test]
    public function testRepeatedThinkingSegmentsRemainVisibleWhileStreamingAndAfterCompletion(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $projector = new TranscriptProjector($dispatcher, new TranscriptProjectionState());
        $blockId = self::SESSION_ID.'_step-1_thinking';
        $accept = static function (string $type, array $payload) use ($projector): void {
            $projector->accept(new RuntimeEvent($type, self::SESSION_ID, 0, $payload));
        };

        $accept('assistant.thinking_started', ['step_id' => 'step-1', 'block_id' => $blockId]);
        $accept('assistant.thinking_delta', ['block_id' => $blockId, 'thinking' => "First reasoning summary.\n"]);
        $accept('assistant.thinking_completed', [
            'block_id' => $blockId,
            'thinking' => "First reasoning summary.\n",
        ]);
        $accept('assistant.thinking_started', ['step_id' => 'step-1', 'block_id' => $blockId]);
        $accept('assistant.thinking_delta', ['block_id' => $blockId, 'thinking' => 'Second reasoning summary.']);

        $streamingHarness = new VirtualTuiHarness(sessionId: self::SESSION_ID.'-streaming');
        $streamingHarness->screen()->setTranscriptBlocks($projector->blocks());
        $streamingHarness->screen()->setWorkingVisible(false);
        $streamingText = $streamingHarness->plainScreenText();
        $this->assertStringContainsString('First reasoning summary.', $streamingText);
        $this->assertStringContainsString('Second reasoning summary.', $streamingText);

        $accept('assistant.thinking_completed', [
            'block_id' => $blockId,
            'thinking' => "First reasoning summary.\nSecond reasoning summary.",
        ]);

        $completedHarness = new VirtualTuiHarness(sessionId: self::SESSION_ID.'-completed');
        $completedHarness->screen()->setTranscriptBlocks($projector->blocks());
        $completedHarness->screen()->setWorkingVisible(false);
        $completedText = $completedHarness->plainScreenText();
        $this->assertStringContainsString('First reasoning summary.', $completedText);
        $this->assertStringContainsString('Second reasoning summary.', $completedText);
        $this->assertCount(1, $projector->blocks());
        $this->assertFalse($projector->blocks()[0]->streaming);
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

        $this->assertStringContainsString('✕', $text, 'Error glyph missing');
        $this->assertStringContainsString('something went wrong', $text, 'Error text missing');
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

        $this->assertStringContainsString('[thinking]', $text, 'Empty thinking fallback missing');
        $this->assertStringContainsString('⋯', $text, 'Thinking glyph missing');
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

        $this->assertStringContainsString('ℹ', $text, 'System info glyph missing');
        $this->assertStringContainsString('info note', $text, 'System text missing');
    }

    /**
     * Test thesis: a provenance-marked system_reminder user.message_submitted
     * with exact complete wrapper projects through UserMessageProjectionSubscriber
     * and renders as ⚠ warning system guidance — not ordinary ❯ Markdown user text.
     */
    #[Test]
    public function testSystemReminderUserMessageRendersAsWarningSystemBlock(): void
    {
        $inner = 'Context is nearly exhausted. Finish now with a concise final answer.';
        $wrapped = "<system-reminder>\n{$inner}\n</system-reminder>";

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $projector = new TranscriptProjector($dispatcher, new TranscriptProjectionState());
        $projector->accept(new RuntimeEvent(
            type: 'user.message_submitted',
            runId: self::SESSION_ID,
            seq: 1,
            payload: [
                'message_id' => 'reminder-virtual-1',
                'text' => $wrapped,
                'metadata' => ['system_reminder' => true],
            ],
        ));

        $blocks = $projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::System, $blocks[0]->kind);

        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks($blocks);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        $this->assertStringContainsString('⚠', $text, 'Warning system glyph missing');
        $this->assertStringContainsString($inner, $text, 'Reminder prose missing');
        $this->assertStringNotContainsString('❯', $text, 'User glyph must not appear for system-reminder');
        $this->assertStringNotContainsString('<system-reminder>', $text, 'Opening wrapper tag must not render');
        $this->assertStringNotContainsString('</system-reminder>', $text, 'Closing wrapper tag must not render');
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

        $this->assertStringContainsString('partial', $text, 'Streaming block text missing');
        $this->assertStringContainsString('...', $text, 'Streaming suffix missing');
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

        $this->assertStringContainsString('🔐', $text, 'Approval glyph missing');
        $this->assertStringContainsString('Approve?', $text, 'Approval text missing');
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

        $this->assertStringContainsString('?', $text, 'Question glyph missing');
        $this->assertStringContainsString('What?', $text, 'Question text missing');
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

        $this->assertStringContainsString('✕', $text, 'Cancelled glyph missing');
        $this->assertStringContainsString('aborted', $text, 'Cancelled text missing');
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
        $this->assertStringContainsString('⋯', $text, 'Thinking glyph missing for hidden block');
        $this->assertStringContainsString('Thinking', $text, 'Thinking placeholder text missing');

        // Raw content must NOT appear
        $this->assertStringNotContainsString('private reasoning', $text);
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

        $this->assertStringContainsString('collapsed but visible', $text,
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

        $this->assertStringNotContainsString('secret', $text,
            'Collapsed thinking must NOT show content when config says hidden',
        );
        // Placeholder must still appear
        $this->assertStringContainsString('⋯', $text);
        $this->assertStringContainsString('Thinking', $text);
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
        $this->assertStringContainsString('bold', $text);
        $this->assertStringNotContainsString('**bold**', $text,
            'Markdown bold delimiters must not appear literally',
        );
        $this->assertStringContainsString('◇', $text, 'Assistant glyph missing');
    }

    #[Test]
    public function testUserMessageRendersMarkdownAndInlineHtmlAsCode(): void
    {
        // Ordinary UserMessage stays Markdown-rendered. Patched Symfony TUI maps
        // parsed HtmlInline to existing inline-code style, so raw Session 4 input
        // remains fully visible with the ❯ glyph while backtick code still works.
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'u1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'Check where we are using <system-reminder>? Run `bin/console`',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        $this->assertStringContainsString('Check where we are using <system-reminder>?', $text,
            'Raw inline HTML must remain fully visible via HtmlInline-as-code rendering',
        );
        $this->assertStringContainsString('bin/console', $text);
        $this->assertStringNotContainsString('`bin/console`', $text,
            'Inline code backticks must not appear literally',
        );
        $this->assertStringContainsString('❯', $text, 'User glyph missing');
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
        $this->assertStringContainsString('reasoning with', $text);
        $this->assertStringContainsString('italic', $text);
        $this->assertStringContainsString('code', $text);
        $this->assertStringNotContainsString('*italic*', $text,
            'Italic delimiters must not appear literally',
        );
        $this->assertStringNotContainsString('`code`', $text,
            'Code backticks must not appear literally',
        );
        $this->assertStringContainsString('⋯', $text, 'Thinking glyph missing');
    }

    #[Test]
    public function testVirtualToolCallShowsYamlArgsWithoutFences(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'read',
                meta: [
                    'tool_name' => 'read',
                    'arguments' => ['path' => './virtual.txt', 'max_bytes' => 512],
                ],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        $this->assertStringContainsString('read', $text);
        $this->assertStringNotContainsString('```', $text);
        $this->assertStringContainsString('path:', $text);
        $this->assertStringContainsString('./virtual.txt', $text);
    }

    #[Test]
    public function testVirtualToolCallArgumentKeysUseConfiguredThemeColorDistinctFromToolTitle(): void
    {
        $palette = new ThemePalette('virtual-tool-args', [
            ThemeColorEnum::ToolTitle->value => '#00ffff',
            ThemeColorEnum::ToolArgumentKey->value => '#ff00ff',
            ThemeColorEnum::ToolArgumentValue->value => '',
            ThemeColorEnum::Muted->value => '#718096',
            ThemeColorEnum::Text->value => '',
        ]);
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID, palette: $palette);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc-args',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'read',
                meta: [
                    'tool_name' => 'read',
                    'arguments' => ['path' => './colored.txt'],
                ],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $ansi = $harness->ansiOutput();
        $plain = $harness->plainScreenText();

        $this->assertStringContainsString('read', $plain);
        $this->assertStringContainsString('path:', $plain);
        $this->assertStringContainsString("\033[38;2;0;255;255m", $ansi, 'Tool title should use configured cyan');
        $this->assertStringContainsString("\033[38;2;255;0;255m", $ansi, 'Argument key should use configured magenta');
        $this->assertMatchesRegularExpression(
            '/\x1b\[38;2;255;0;255mpath\x1b\[39m:/',
            $ansi,
            'Argument key path must carry ToolArgumentKey ANSI, not ToolTitle',
        );
    }

    #[Test]
    public function testVirtualViewImageExchangeArgumentKeyUsesConfiguredThemeColor(): void
    {
        $palette = new ThemePalette('virtual-view-image-args', [
            ThemeColorEnum::ToolTitle->value => '#00ffff',
            ThemeColorEnum::ToolArgumentKey->value => '#ff00ff',
            ThemeColorEnum::ToolArgumentValue->value => '',
            ThemeColorEnum::ToolOutput->value => '#39ff14',
            ThemeColorEnum::Muted->value => '#718096',
            ThemeColorEnum::Text->value => '',
        ]);
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID, palette: $palette);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc-view-image',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'view_image',
                meta: [
                    'tool_call_id' => 'call-view-image',
                    'tool_name' => 'view_image',
                    'arguments' => ['path' => '/tmp/shot.png'],
                ],
            ),
            new TranscriptBlock(
                id: 'tr-view-image',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 2,
                text: 'view_image',
                meta: [
                    'tool_call_id' => 'call-view-image',
                    'tool_name' => 'view_image',
                    'result' => [
                        'type' => 'view_image',
                        'path' => '/tmp/shot.png',
                        'media_type' => 'image/png',
                        'width' => 10,
                        'height' => 20,
                        'bytes' => 99,
                    ],
                    'is_error' => false,
                ],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $ansi = $harness->ansiOutput();
        $plain = $harness->plainScreenText();

        $this->assertStringContainsString('view_image', $plain);
        $this->assertStringContainsString('path:', $plain);
        $this->assertStringContainsString('/tmp/shot.png', $plain);
        $this->assertMatchesRegularExpression(
            '/\x1b\[38;2;255;0;255mpath\x1b\[39m:/',
            $ansi,
            'view_image exchange argument key must use ToolArgumentKey ANSI',
        );
        $this->assertStringContainsString("\033[38;2;0;255;255m", $ansi, 'view_image tool title should remain distinct');
    }

    #[Test]
    public function testVirtualToolResultLiteralEllipsisKeepsToolOutputColor(): void
    {
        $palette = new ThemePalette('virtual-literal-ellipsis', [
            ThemeColorEnum::ToolOutput->value => '#39ff14',
            ThemeColorEnum::Muted->value => '#718096',
            ThemeColorEnum::Text->value => '',
        ]);
        $body = implode("\n", [
            '… literal ellipsis in tool output',
            'line1',
            'line2',
            'line3',
        ]);
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            palette: $palette,
            displayConfig: new TranscriptDisplayConfig(toolResultPreviewLines: 2),
        );
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tr-literal-ellipsis',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 1,
                text: $body,
                meta: ['tool_name' => 'read', 'result' => $body, 'is_error' => false],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $ansi = $harness->ansiOutput();
        $plain = $harness->plainScreenText();

        $this->assertStringContainsString('… literal ellipsis in tool output', $plain);
        $this->assertStringContainsString('… 2 more lines', $plain);
        $this->assertMatchesRegularExpression(
            '/\x1b\[38;2;57;255;20m\s*… literal ellipsis in tool output/',
            $ansi,
            'Literal U+2026 tool output must keep ToolOutput color',
        );
        $this->assertMatchesRegularExpression(
            '/\x1b\[3m(?:\x1b\[[0-9;]*m)*… 2 more lines/',
            $ansi,
            'Generated collapsed indicator must stay muted+italic',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\x1b\[38;2;57;255;20m\s*… 2 more lines/',
            $ansi,
            'Generated collapsed indicator must not inherit ToolOutput color',
        );
    }

    #[Test]
    public function testVirtualLongToolResultPreviewsByDefault(): void
    {
        $body = implode("\n", ['v0', 'v1', 'v2', 'v3']);
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            displayConfig: new TranscriptDisplayConfig(toolResultPreviewLines: 2),
        );
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tr',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 2,
                text: $body,
                meta: ['tool_name' => 'read', 'result' => $body, 'is_error' => false],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        $this->assertStringContainsString('v0', $text);
        $this->assertStringContainsString('v1', $text);
        $this->assertStringNotContainsString('v3', $text);
        $this->assertStringContainsString('more line', $text);
    }

    #[Test]
    public function testVirtualLongToolResultRendersFullWhenPreviewExpanded(): void
    {
        $body = implode("\n", ['full0', 'full1', 'full2']);
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            displayConfig: new TranscriptDisplayConfig(toolResultPreviewLines: 1),
            displayState: new TranscriptDisplayState(previewableBlocksExpanded: true),
        );
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tr-full',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 2,
                text: $body,
                meta: ['tool_name' => 'read', 'result' => $body, 'is_error' => false],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        $this->assertStringContainsString('full2', $text);
        $this->assertStringNotContainsString('more line', $text);
    }

    #[Test]
    public function testVirtualLongToolCallArgumentsPreviewByDefault(): void
    {
        $patchLines = [];
        for ($i = 0; $i < 8; ++$i) {
            $patchLines[] = '+line'.$i;
        }
        $patch = implode('
', array_merge(['---', '+++', '@@'], $patchLines));
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            displayConfig: new TranscriptDisplayConfig(diffPreviewLines: 3),
            displayState: new TranscriptDisplayState(previewableBlocksExpanded: false),
        );
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc-long',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'edit',
                meta: [
                    'tool_name' => 'edit',
                    'arguments' => ['path' => '/tmp/test.md', 'patch' => $patch],
                ],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        $this->assertStringContainsString('path: /tmp/test.md', $text);
        $this->assertStringNotContainsString('patch: |', $text);
        $this->assertStringNotContainsString('+line7', $text);
        $this->assertStringContainsString('more line', $text);
    }

    #[Test]
    public function testVirtualLongToolCallArgumentsRenderFullWhenPreviewExpanded(): void
    {
        $patchLines = [];
        for ($i = 0; $i < 5; ++$i) {
            $patchLines[] = '+line'.$i;
        }
        $patch = implode('
', array_merge(['---', '+++', '@@'], $patchLines));
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            displayConfig: new TranscriptDisplayConfig(diffPreviewLines: 2),
            displayState: new TranscriptDisplayState(previewableBlocksExpanded: true),
        );
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc-expanded',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'edit',
                meta: [
                    'tool_name' => 'edit',
                    'arguments' => ['path' => '/tmp/test.md', 'patch' => $patch],
                ],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        $this->assertStringContainsString('+line4', $text);
        $this->assertStringNotContainsString('more line', $text);
    }

    #[Test]
    public function testVirtualEditToolCallRendersDiffInCardBody(): void
    {
        $patch = "--- a/x\n+++ b/x\n@@\n-old\n+new";
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc-edit-virtual',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'edit',
                meta: [
                    'tool_name' => 'edit',
                    'arguments' => ['path' => 'src/Foo.php', 'patch' => $patch],
                ],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        $this->assertStringContainsString('edit', $text);
        $this->assertStringContainsString('path: src/Foo.php', $text);
        $this->assertStringContainsString('+new', $text);
        $this->assertStringNotContainsString('patch: |', $text);
    }

    #[Test]
    public function testVirtualWriteToolCallRendersContentPreview(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc-write-virtual',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'write',
                meta: [
                    'tool_name' => 'write',
                    'arguments' => ['path' => 'out.txt', 'content' => "alpha\nbeta"],
                ],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();

        $this->assertStringContainsString('write', $text);
        $this->assertStringContainsString('path: out.txt', $text);
        $this->assertStringContainsString('alpha', $text);
        $this->assertStringNotContainsString('content: |', $text);
    }

    #[Test]
    public function testVirtualAskHumanSequenceShowsQuestionWithoutPayloadNoise(): void
    {
        $prompt = "List:\n1. **first**\n2. second";
        $json = '{"kind":"interrupt","question_id":"ah_virtual","prompt":"List"}';
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc-ask',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'ask_human',
                meta: [
                    'tool_name' => 'ask_human',
                    'arguments' => ['prompt' => $prompt, 'schema' => ['type' => 'string']],
                ],
            ),
            new TranscriptBlock(
                id: 'tr-ask',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 2,
                text: $json,
                meta: ['tool_name' => 'ask_human', 'result' => $json, 'is_error' => false],
            ),
            new TranscriptBlock(
                id: 'q-ask',
                kind: TranscriptBlockKindEnum::Question,
                runId: self::SESSION_ID,
                seq: 3,
                text: $prompt,
                meta: ['prompt' => $prompt, 'status' => 'pending'],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $harness->plainScreenText());

        $this->assertStringContainsString(TranscriptGlyphs::GLYPH_QUESTION, $plain);
        $this->assertStringContainsString('first', $plain);
        $this->assertStringNotContainsString('**first**', $plain);
        $this->assertStringNotContainsString('kind":"interrupt', $plain);
        $this->assertStringNotContainsString('question_id', $plain);
        $this->assertStringNotContainsString('schema', $plain);
        $this->assertStringNotContainsString('ask_human', $plain);
    }

    #[Test]
    public function testDuplicateToolResultsForSameCallIdCollapseInChatScreen(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc-1',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'bash',
                meta: [
                    'tool_call_id' => 'call-a',
                    'tool_name' => 'bash',
                    'arguments' => ['command' => 'composer install'],
                ],
            ),
            new TranscriptBlock(
                id: 'tr-empty',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 2,
                text: 'bash',
                meta: [
                    'tool_call_id' => 'call-a',
                    'tool_name' => 'bash',
                    'result' => '',
                    'is_error' => false,
                ],
            ),
            new TranscriptBlock(
                id: 'tr-full',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 3,
                text: 'bash',
                meta: [
                    'tool_call_id' => 'call-a',
                    'tool_name' => 'bash',
                    'result' => "Installing dependencies...\n",
                    'is_error' => false,
                ],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();
        $this->assertSame(1, substr_count($text, 'bash'), 'Expected one collapsed bash card: '.$text);
        $this->assertStringContainsString('composer install', $text);
        $this->assertStringContainsString('Installing dependencies', $text);
    }

    #[Test]
    public function testParallelBashToolExchangesCollapseInChatScreen(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tc-1',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'bash',
                meta: [
                    'tool_call_id' => 'call-a',
                    'tool_name' => 'bash',
                    'arguments' => ['command' => 'find bin'],
                ],
            ),
            new TranscriptBlock(
                id: 'tr-1',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 2,
                text: 'bash',
                meta: [
                    'tool_call_id' => 'call-a',
                    'tool_name' => 'bash',
                    'result' => '/path/bin/console',
                    'is_error' => false,
                ],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $text = $harness->plainScreenText();
        $this->assertSame(1, substr_count($text, 'bash'), 'Collapsed exchange should show one bash header: '.$text);
        $this->assertStringContainsString('find bin', $text);
        $this->assertStringContainsString('/path/bin/console', $text);
    }

    #[Test]
    public function testDistinctBlocksHaveBlankLineSeparationAcrossWidths(): void
    {
        foreach ([80, 120] as $columns) {
            $harness = new VirtualTuiHarness(columns: $columns, sessionId: self::SESSION_ID);
            $harness->screen()->setTranscriptBlocks([
                new TranscriptBlock(
                    id: 'u-gap',
                    kind: TranscriptBlockKindEnum::UserMessage,
                    runId: self::SESSION_ID,
                    seq: 1,
                    text: 'prompt for spacing',
                ),
                new TranscriptBlock(
                    id: 'a-gap',
                    kind: TranscriptBlockKindEnum::AssistantMessage,
                    runId: self::SESSION_ID,
                    seq: 2,
                    text: 'assistant spacing reply',
                ),
                new TranscriptBlock(
                    id: 'tc-gap',
                    kind: TranscriptBlockKindEnum::ToolCall,
                    runId: self::SESSION_ID,
                    seq: 3,
                    text: 'bash',
                    meta: [
                        'tool_call_id' => 'call-gap',
                        'tool_name' => 'bash',
                        'arguments' => ['command' => 'echo spacing'],
                    ],
                ),
            ]);
            $harness->screen()->setWorkingVisible(false);

            $text = $harness->plainScreenText();
            $this->assertMatchesRegularExpression(
                '/prompt for spacing\n\s*\n.*assistant spacing reply/s',
                $text,
                "Expected blank row between user and assistant at width {$columns}",
            );
            $this->assertMatchesRegularExpression(
                '/assistant spacing reply\n\s*\n.*●/s',
                $text,
                "Expected blank row between assistant and tool at width {$columns}",
            );
        }
    }

    #[Test]
    public function testCollapsedPreviewEllipsisIsItalicInAnsiOutput(): void
    {
        $body = implode("\n", ['line0', 'line1', 'line2', 'line3']);
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            displayConfig: new TranscriptDisplayConfig(toolResultPreviewLines: 2),
        );
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tr-italic',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 1,
                text: $body,
                meta: ['tool_name' => 'read', 'result' => $body, 'is_error' => false],
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $ansi = $harness->ansiOutput();
        $this->assertMatchesRegularExpression(
            '/\x1b\[3m(?:\x1b\[[0-9;]*m)*… 2 more lines/',
            $ansi,
            'Collapsed ellipsis must carry italic ANSI styling',
        );
        $this->assertStringContainsString('… 2 more lines', $harness->plainScreenText());
        $this->assertStringNotContainsString('line3', $harness->plainScreenText());
    }

    #[Test]
    public function testWideGlyphMarkersAlignTextColumnWithNarrowGlyphs(): void
    {
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            new TranscriptBlock(
                id: 'tool-align',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: self::SESSION_ID,
                seq: 1,
                text: 'bash',
                meta: [
                    'tool_call_id' => 'call-align',
                    'tool_name' => 'bash',
                    'arguments' => ['command' => 'true'],
                ],
            ),
            new TranscriptBlock(
                id: 'progress-align',
                kind: TranscriptBlockKindEnum::Progress,
                runId: self::SESSION_ID,
                seq: 2,
                text: 'working on it',
            ),
            new TranscriptBlock(
                id: 'approval-align',
                kind: TranscriptBlockKindEnum::Approval,
                runId: self::SESSION_ID,
                seq: 3,
                text: 'needs approval',
            ),
        ]);
        $harness->screen()->setWorkingVisible(false);

        $lines = preg_split("/\n/", $harness->plainScreenText()) ?: [];
        $toolLine = null;
        $progressLine = null;
        $approvalLine = null;
        foreach ($lines as $line) {
            if (null === $toolLine && str_contains($line, '●') && str_contains($line, 'bash')) {
                $toolLine = $line;
            }
            if (null === $progressLine && str_contains($line, '⏳') && str_contains($line, 'working on it')) {
                $progressLine = $line;
            }
            if (null === $approvalLine && str_contains($line, '🔐') && str_contains($line, 'needs approval')) {
                $approvalLine = $line;
            }
        }

        $this->assertNotNull($toolLine, 'Tool marker line missing');
        $this->assertNotNull($progressLine, 'Progress marker line missing');
        $this->assertNotNull($approvalLine, 'Approval marker line missing');

        $toolTextCol = AnsiUtils::visibleWidth(mb_substr($toolLine, 0, (int) mb_strpos($toolLine, 'bash')));
        $progressTextCol = AnsiUtils::visibleWidth(mb_substr($progressLine, 0, (int) mb_strpos($progressLine, 'working on it')));
        $approvalTextCol = AnsiUtils::visibleWidth(mb_substr($approvalLine, 0, (int) mb_strpos($approvalLine, 'needs approval')));
        $this->assertSame($toolTextCol, $progressTextCol, 'Progress text should share tool text column: '.$progressLine);
        $this->assertSame($toolTextCol, $approvalTextCol, 'Approval text should share tool text column: '.$approvalLine);
    }
}
