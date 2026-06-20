<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptBlockRenderer;
use Ineersa\Tui\Transcript\TranscriptBlockWidget;
use Ineersa\Tui\Widget\TuiRenderContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranscriptBlockRenderer::class)]
#[CoversClass(TranscriptBlockWidget::class)]
final class TranscriptBlockRendererTest extends TestCase
{
    private TuiRenderContext $context;
    private TranscriptBlockRenderer $renderer;

    protected function setUp(): void
    {
        $this->context = new TuiRenderContext(
            terminalWidth: 80,
        );
        $this->renderer = new TranscriptBlockRenderer();
    }

    // ── Empty / widget-level tests ─────────────────────────

    public function testEmptyWidgetShowsWelcome(): void
    {
        $widget = new TranscriptBlockWidget();
        $lines = $widget->render($this->context);

        self::assertCount(1, $lines);
        self::assertStringContainsString('Welcome to Agent Core', $lines[0]);
    }

    public function testWidgetWithSingleBlock(): void
    {
        $widget = new TranscriptBlockWidget();
        $widget->addBlock(new TranscriptBlock(
            id: 'msg-1',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'run-1',
            seq: 1,
            text: 'Hello world',
        ));

        $lines = $widget->render($this->context);

        self::assertNotEmpty($lines);
        self::assertStringContainsString('❯', $lines[0]);
        self::assertStringContainsString('Hello world', $lines[0]);
    }

    public function testSetBlocksReplacesAll(): void
    {
        $widget = new TranscriptBlockWidget();
        $widget->addBlock(new TranscriptBlock(
            id: 'old',
            kind: TranscriptBlockKindEnum::System,
            runId: 'r1',
            seq: 1,
            text: 'old',
        ));
        $widget->setBlocks([
            new TranscriptBlock(
                id: 'new',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'r1',
                seq: 2,
                text: 'new',
            ),
        ]);

        $lines = $widget->render($this->context);

        self::assertCount(1, $lines);
        self::assertStringContainsString('new', $lines[0]);
        self::assertStringNotContainsString('old', $lines[0]);
    }

    public function testGetBlocksReturnsSetBlocks(): void
    {
        $block = new TranscriptBlock(
            id: 'b1',
            kind: TranscriptBlockKindEnum::System,
            runId: 'r1',
            seq: 1,
            text: 'test',
        );
        $widget = new TranscriptBlockWidget();
        $widget->setBlocks([$block]);

        self::assertSame([$block], $widget->getBlocks());
    }

    // ── Block-kind prefix / color smoke ────────────────────

    /** @return array<string, array{TranscriptBlockKindEnum, string, string}> */
    public static function blockKindProvider(): array
    {
        return [
            'user message' => [TranscriptBlockKindEnum::UserMessage, '❯', 'Hello'],
            'assistant message' => [TranscriptBlockKindEnum::AssistantMessage, '◇', 'Hi'],
            'assistant thinking' => [TranscriptBlockKindEnum::AssistantThinking, '⋯', 'reasoning'],
            'tool call' => [TranscriptBlockKindEnum::ToolCall, '●', ''],
            'tool result' => [TranscriptBlockKindEnum::ToolResult, '●', ''],
            'progress' => [TranscriptBlockKindEnum::Progress, '⏳', 'working'],
            'question' => [TranscriptBlockKindEnum::Question, '?', 'What?'],
            'approval' => [TranscriptBlockKindEnum::Approval, '🔐', 'Approve?'],
            'cancelled' => [TranscriptBlockKindEnum::Cancelled, '✕', 'aborted'],
            'error' => [TranscriptBlockKindEnum::Error, '✕', 'boom'],
            'system' => [TranscriptBlockKindEnum::System, '·', 'notice'],
        ];
    }

    #[DataProvider('blockKindProvider')]
    public function testBlockKindHasExpectedPrefix(
        TranscriptBlockKindEnum $kind,
        string $expectedPrefix,
        string $text,
    ): void {
        $block = new TranscriptBlock(
            id: 'b',
            kind: $kind,
            runId: 'r',
            seq: 1,
            text: $text,
        );

        $lines = $this->renderer->renderBlock($block, $this->context);

        self::assertNotEmpty($lines);
        self::assertStringContainsString($expectedPrefix, $lines[0]);
    }

    // ── Streaming suffix ────────────────────────────────────

    public function testStreamingBlockShowsEllipsis(): void
    {
        $block = new TranscriptBlock(
            id: 'b',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'r',
            seq: 1,
            text: 'partial response',
            streaming: true,
        );

        $lines = $this->renderer->renderBlock($block, $this->context);

        self::assertNotEmpty($lines);
        self::assertStringEndsWith('...', $lines[0]);
    }

    public function testFinalizedBlockHasNoEllipsis(): void
    {
        $block = new TranscriptBlock(
            id: 'b',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'r',
            seq: 1,
            text: 'complete',
            streaming: false,
        );

        $lines = $this->renderer->renderBlock($block, $this->context);

        self::assertNotEmpty($lines);
        self::assertStringNotContainsString('...', $lines[0]);
    }

    // ── Meta fallback for empty text ────────────────────────

    public function testToolCallWithEmptyTextShowsToolNameFromMeta(): void
    {
        $block = new TranscriptBlock(
            id: 'tc-1',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 2,
            text: '',
            meta: ['tool_name' => 'bash'],
        );

        $lines = $this->renderer->renderBlock($block, $this->context);

        self::assertNotEmpty($lines);
        self::assertStringContainsString('bash', $lines[0]);
    }

    public function testToolCallWithEmptyTextAndNoMetaShowsDefaultLabel(): void
    {
        $block = new TranscriptBlock(
            id: 'tc-2',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 2,
            text: '',
            meta: [],
        );

        $lines = $this->renderer->renderBlock($block, $this->context);

        self::assertNotEmpty($lines);
        self::assertStringContainsString('Tool call', $lines[0]);
    }

    public function testAssistantMessageWithEmptyTextShowsPlaceholder(): void
    {
        $block = new TranscriptBlock(
            id: 'b',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'r',
            seq: 1,
            text: '',
        );

        $lines = $this->renderer->renderBlock($block, $this->context);

        self::assertNotEmpty($lines);
        self::assertStringContainsString('[assistant]', $lines[0]);
    }

    // ── Word wrapping ───────────────────────────────────────

    public function testLongLineIsWrapped(): void
    {
        $text = str_repeat('word ', 50);
        $block = new TranscriptBlock(
            id: 'b',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'r',
            seq: 1,
            text: $text,
        );

        $lines = $this->renderer->renderBlock($block, $this->context->withWidth(40));

        self::assertGreaterThan(1, \count($lines));
    }

    public function testShortLineIsNotWrapped(): void
    {
        $block = new TranscriptBlock(
            id: 'b',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'r',
            seq: 1,
            text: 'short',
        );

        $lines = $this->renderer->renderBlock($block, $this->context);

        self::assertCount(1, $lines);
    }

    // ── Collapsed blocks ────────────────────────────────────

    public function testCollapsedBlockRendersText(): void
    {
        $block = new TranscriptBlock(
            id: 'b',
            kind: TranscriptBlockKindEnum::AssistantThinking,
            runId: 'r',
            seq: 1,
            text: 'hidden thoughts',
            collapsed: true,
        );

        $lines = $this->renderer->renderBlock($block, $this->context);

        self::assertNotEmpty($lines);
        self::assertStringContainsString('hidden thoughts', $lines[0]);
    }

    // ── Tool result with text ───────────────────────────────

    public function testToolResultWithText(): void
    {
        $block = new TranscriptBlock(
            id: 'tr-1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 3,
            text: 'file.txt: 3 lines',
            meta: ['tool_name' => 'read'],
        );

        $lines = $this->renderer->renderBlock($block, $this->context);

        self::assertNotEmpty($lines);
        self::assertStringContainsString('●', $lines[0]);
        self::assertStringContainsString('file.txt', $lines[0]);
    }

    // ── Empty text edge cases ───────────────────────────────

    public function testEmptyTextForSystemShowsEmptyLine(): void
    {
        $block = new TranscriptBlock(
            id: 'b',
            kind: TranscriptBlockKindEnum::System,
            runId: 'r',
            seq: 1,
            text: '',
        );

        $lines = $this->renderer->renderBlock($block, $this->context);

        self::assertNotEmpty($lines);
        // should render "  · " with empty text
        self::assertStringContainsString('·', $lines[0]);
    }

    public function testEmptyTextForUserMessageShowsPrefixOnly(): void
    {
        $block = new TranscriptBlock(
            id: 'b',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'r',
            seq: 1,
            text: '',
        );

        $lines = $this->renderer->renderBlock($block, $this->context);

        self::assertNotEmpty($lines);
        self::assertStringContainsString('❯', $lines[0]);
    }

    // ── RunId and seq have no visual rendering effect ───────

    public function testRunIdAndSeqDoNotAffectOutput(): void
    {
        $blockA = new TranscriptBlock(
            id: 'a',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'r1',
            seq: 1,
            text: 'hello',
        );
        $blockB = new TranscriptBlock(
            id: 'b',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'r2',
            seq: 99,
            text: 'hello',
        );

        self::assertSame(
            $this->renderer->renderBlock($blockA, $this->context),
            $this->renderer->renderBlock($blockB, $this->context),
        );
    }

    // ── Widget renders multiple blocks with gaps ────────────

    public function testMultipleBlocksAreRenderedInOrder(): void
    {
        $widget = new TranscriptBlockWidget();
        $widget->setBlocks([
            new TranscriptBlock(
                id: 'u1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'r',
                seq: 1,
                text: 'prompt',
            ),
            new TranscriptBlock(
                id: 'a1',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'r',
                seq: 2,
                text: 'response',
            ),
        ]);

        $lines = $widget->render($this->context);

        self::assertCount(2, $lines);
        self::assertStringContainsString('prompt', $lines[0]);
        self::assertStringContainsString('response', $lines[1]);
    }

    // ── Custom renderer injection ──────────────────────────

    public function testWidgetUsesInjectedRenderer(): void
    {
        $renderer = new TranscriptBlockRenderer();
        $widget = new TranscriptBlockWidget($renderer);

        $block = new TranscriptBlock(
            id: 'b',
            kind: TranscriptBlockKindEnum::Error,
            runId: 'r',
            seq: 1,
            text: 'fail',
        );

        $lines = $widget->render($this->context);

        // Verify widget renders with injected renderer (block is added)
        $widget->addBlock($block);
        $linesAfter = $widget->render($this->context);

        self::assertCount(1, $linesAfter);
        self::assertStringContainsString('✕', $linesAfter[0]);
    }

    // ── Theme applies color ─────────────────────────────────

    public function testThemeColorIsApplied(): void
    {
        // Use a theme palette with a custom color to verify it's applied
        $palette = new ThemePalette(name: 'test', colors: [
            'user_message' => '#ff0000',
        ]);
        $theme = new DefaultTheme($palette);
        $context = $this->context->withTheme($theme);

        $block = new TranscriptBlock(
            id: 'b',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'r',
            seq: 1,
            text: 'red',
        );

        $lines = $this->renderer->renderBlock($block, $context);

        self::assertNotEmpty($lines);
        self::assertStringContainsString("\x1b[", $lines[0], 'ANSI escape codes expected');
    }
}
