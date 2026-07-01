<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptGlyphs;
use Ineersa\Tui\Transcript\TranscriptBlockWidgetFactory;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
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

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Welcome to Agent Core', $lines[0]);
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

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('❯', $lines[0]);
        $this->assertStringContainsString('Hello world', $lines[0]);
    }

    public function testTurnSeparatorInsertedBeforeLaterUserMessage(): void
    {
        $widget = new TranscriptBlockWidget();
        $widget->setBlocks([
            new TranscriptBlock(
                id: 'u1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'run-1',
                seq: 1,
                text: 'first turn',
            ),
            new TranscriptBlock(
                id: 'a1',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'run-1',
                seq: 2,
                text: 'assistant reply',
            ),
            new TranscriptBlock(
                id: 'u2',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'run-1',
                seq: 3,
                text: 'second turn',
            ),
        ]);

        $lines = $widget->render($this->context);
        $plainLines = array_map(static fn (string $line): string => preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line, $lines);

        $expectedSeparator = str_repeat(TranscriptGlyphs::TURN_SEPARATOR_CHAR, $this->context->terminalWidth);
        $separatorLines = array_values(array_filter(
            $plainLines,
            static fn (string $line): bool => $line === $expectedSeparator,
        ));

        $this->assertCount(1, $separatorLines, 'Expected exactly one user-turn separator');
        $this->assertSame($expectedSeparator, $separatorLines[0], 'Separator should span terminal width');
        $this->assertStringContainsString('first turn', implode("
", $plainLines));
        $this->assertStringContainsString('second turn', implode("
", $plainLines));
    }

    public function testFirstUserMessageHasNoLeadingSeparator(): void
    {
        $widget = new TranscriptBlockWidget();
        $widget->setBlocks([
            new TranscriptBlock(
                id: 'u1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'run-1',
                seq: 1,
                text: 'only turn',
            ),
        ]);

        $lines = $widget->render($this->context);
        $plainLines = array_map(static fn (string $line): string => preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line, $lines);

        foreach ($plainLines as $line) {
            if ('' === $line) {
                continue;
            }
            $this->assertNotSame(str_repeat(TranscriptGlyphs::TURN_SEPARATOR_CHAR, $this->context->terminalWidth), $line, 'First user turn should not be preceded by a separator');
        }
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

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('new', $lines[0]);
        $this->assertStringNotContainsString('old', $lines[0]);
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

        $this->assertSame([$block], $widget->getBlocks());
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

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString($expectedPrefix, $lines[0]);
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

        $this->assertNotEmpty($lines);
        $this->assertStringEndsWith('...', rtrim($lines[0]));
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

        $this->assertNotEmpty($lines);
        $this->assertStringNotContainsString('...', $lines[0]);
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

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('bash', $lines[0]);
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

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('Tool call', $lines[0]);
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

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('[assistant]', $lines[0]);
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

        $this->assertGreaterThan(1, \count($lines));
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

        $this->assertCount(1, $lines);
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

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('hidden thoughts', $lines[0]);
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

        $output = $this->renderJoined($block);

        $this->assertStringContainsString('●', $output);
        $this->assertStringContainsString('read', $output);
        $this->assertStringContainsString('file.txt', $output);
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

        $this->assertNotEmpty($lines);
        // should render "  · " with empty text
        $this->assertStringContainsString('·', $lines[0]);
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

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('❯', $lines[0]);
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

        $this->assertSame(
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

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('prompt', $lines[0]);
        $this->assertStringContainsString('response', $lines[1]);
    }

    // ── Custom renderer injection ──────────────────────────

    public function testWidgetUsesDefaultRenderer(): void
    {
        $widget = new TranscriptBlockWidget();

        $block = new TranscriptBlock(
            id: 'b',
            kind: TranscriptBlockKindEnum::Error,
            runId: 'r',
            seq: 1,
            text: 'fail',
        );

        $widget->addBlock($block);
        $lines = $widget->render($this->context);

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('✕', $lines[0]);
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

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString("\x1b[", $lines[0], 'ANSI escape codes expected');
    }

    // ── Tool cards (RENDER-04) ─────────────────────────────

    private function renderJoined(TranscriptBlock $block, ?TranscriptDisplayConfig $config = null, ?TranscriptDisplayState $state = null): string
    {
        $renderer = new TranscriptBlockRenderer(
            factory: new TranscriptBlockWidgetFactory(
                displayConfig: $config ?? new TranscriptDisplayConfig(),
                displayState: $state ?? new TranscriptDisplayState(),
            ),
        );

        return implode("\n", $renderer->renderBlock($block, $this->context));
    }

    public function testToolCallWithArgumentsRendersYamlWithoutFences(): void
    {
        $block = new TranscriptBlock(
            id: 'tc-yaml',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 2,
            text: 'read',
            meta: [
                'tool_name' => 'read',
                'arguments' => ['path' => './test.txt', 'max_bytes' => 1024],
            ],
        );

        $output = $this->renderJoined($block);

        $this->assertStringContainsString('read', $output);
        $this->assertStringContainsString('path:', $output);
        $this->assertStringContainsString('./test.txt', $output);
        $this->assertStringContainsString('max_bytes:', $output);
        $this->assertStringNotContainsString('(path:', $output);
    }

    public function testToolCallWithoutArgumentsRendersHeaderOnly(): void
    {
        $block = new TranscriptBlock(
            id: 'tc-no-args',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 2,
            text: '',
            meta: ['tool_name' => 'bash'],
        );

        $output = $this->renderJoined($block);

        $this->assertStringContainsString('bash', $output);
        $this->assertStringNotContainsString('```', $output);
    }

    public function testStreamingToolCallPreservesStreamingSuffix(): void
    {
        $block = new TranscriptBlock(
            id: 'tc-stream',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 2,
            text: '',
            meta: ['tool_name' => 'read'],
            streaming: true,
        );

        $output = $this->renderJoined($block);

        $this->assertStringContainsString('read...', $output);
    }

    public function testEditToolCallRendersPatchAsDiffNotYaml(): void
    {
        $patch = "--- a/tmp/test.md\n+++ b/tmp/test.md\n@@\n-old\n+new";
        $block = new TranscriptBlock(
            id: 'tc-edit',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 2,
            text: 'edit',
            meta: [
                'tool_name' => 'edit',
                'arguments' => ['path' => '/tmp/test.md', 'patch' => $patch],
            ],
        );

        $output = $this->renderJoined($block);

        $this->assertStringContainsString('edit', $output);
        $this->assertStringContainsString('path: /tmp/test.md', $output);
        $this->assertStringNotContainsString('patch: |', $output);
        $this->assertStringContainsString('--- a/tmp/test.md', $output);
        $this->assertStringContainsString('+new', $output);
        $this->assertStringContainsString('-old', $output);
    }

    public function testLongToolCallArgumentsPreviewByDefault(): void
    {
        $patchLines = [];
        for ($i = 0; $i < 10; ++$i) {
            $patchLines[] = '+line'.$i;
        }
        $patch = implode("
", array_merge(['---', '+++', '@@'], $patchLines));
        $block = new TranscriptBlock(
            id: 'tc-long-args',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 2,
            text: 'edit',
            meta: [
                'tool_name' => 'edit',
                'arguments' => ['path' => '/tmp/test.md', 'patch' => $patch],
            ],
        );

        $output = $this->renderJoined(
            $block,
            new TranscriptDisplayConfig(diffPreviewLines: 4),
            new TranscriptDisplayState(previewableBlocksExpanded: false),
        );

        $this->assertStringContainsString('path: /tmp/test.md', $output);
        $this->assertStringNotContainsString('patch: |', $output);
        $this->assertStringNotContainsString('+line9', $output);
        $this->assertStringContainsString('more line', $output);
    }

    public function testLongToolCallArgumentsRenderFullWhenPreviewExpanded(): void
    {
        $patchLines = [];
        for ($i = 0; $i < 6; ++$i) {
            $patchLines[] = '+line'.$i;
        }
        $patch = implode("
", array_merge(['---', '+++', '@@'], $patchLines));
        $block = new TranscriptBlock(
            id: 'tc-expanded-args',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 2,
            text: 'edit',
            meta: [
                'tool_name' => 'edit',
                'arguments' => ['path' => '/tmp/test.md', 'patch' => $patch],
            ],
        );

        $output = $this->renderJoined(
            $block,
            new TranscriptDisplayConfig(diffPreviewLines: 2),
            new TranscriptDisplayState(previewableBlocksExpanded: true),
        );

        $this->assertStringContainsString('+line5', $output);
        $this->assertStringNotContainsString('more line', $output);
    }

    public function testShortToolResultRendersFullWithoutEllipsis(): void
    {
        $block = new TranscriptBlock(
            id: 'tr-short',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 3,
            text: 'line0\nline1',
            meta: ['tool_name' => 'read', 'result' => "line0\nline1", 'is_error' => false],
        );

        $output = $this->renderJoined($block, new TranscriptDisplayConfig(toolResultPreviewLines: 8));

        $this->assertStringContainsString('line0', $output);
        $this->assertStringContainsString('line1', $output);
        $this->assertStringNotContainsString('more line', $output);
    }

    public function testLongSuccessfulToolResultPreviewsByDefault(): void
    {
        $body = implode("\n", ['line0', 'line1', 'line2', 'line3', 'line4']);
        $block = new TranscriptBlock(
            id: 'tr-long',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 3,
            text: $body,
            meta: ['tool_name' => 'read', 'result' => $body, 'is_error' => false],
        );

        $output = $this->renderJoined(
            $block,
            new TranscriptDisplayConfig(toolResultPreviewLines: 2),
            new TranscriptDisplayState(previewableBlocksExpanded: false),
        );

        $this->assertStringContainsString('line0', $output);
        $this->assertStringContainsString('line1', $output);
        $this->assertStringNotContainsString('line4', $output);
        $this->assertStringContainsString('more line', $output);
    }

    public function testLongSuccessfulToolResultRendersFullWhenPreviewExpanded(): void
    {
        $body = implode("\n", ['line0', 'line1', 'line2', 'line3']);
        $block = new TranscriptBlock(
            id: 'tr-expanded',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 3,
            text: $body,
            meta: ['tool_name' => 'read', 'result' => $body, 'is_error' => false],
        );

        $output = $this->renderJoined(
            $block,
            new TranscriptDisplayConfig(toolResultPreviewLines: 2),
            new TranscriptDisplayState(previewableBlocksExpanded: true),
        );

        $this->assertStringContainsString('line0', $output);
        $this->assertStringContainsString('line3', $output);
        $this->assertStringNotContainsString('more line', $output);
    }

    public function testLongErrorToolResultRendersFull(): void
    {
        $body = implode("\n", ['err0', 'err1', 'err2', 'err3', 'err4']);
        $block = new TranscriptBlock(
            id: 'tr-error',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 3,
            text: $body,
            meta: ['tool_name' => 'read', 'result' => $body, 'is_error' => true],
        );

        $output = $this->renderJoined(
            $block,
            new TranscriptDisplayConfig(toolResultPreviewLines: 2),
            new TranscriptDisplayState(previewableBlocksExpanded: false),
        );

        $this->assertStringContainsString('err4', $output);
        $this->assertStringNotContainsString('more line', $output);
    }

    public function testLongCancelledToolResultRendersFull(): void
    {
        $body = implode("\n", ['c0', 'c1', 'c2', 'c3']);
        $block = new TranscriptBlock(
            id: 'tr-cancelled',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 3,
            text: $body,
            meta: ['tool_name' => 'bash', 'result' => $body, 'cancelled' => true, 'is_error' => true],
        );

        $output = $this->renderJoined($block, new TranscriptDisplayConfig(toolResultPreviewLines: 1));

        $this->assertStringContainsString('c3', $output);
        $this->assertStringNotContainsString('more line', $output);
    }

    public function testLongTimedOutToolResultRendersFull(): void
    {
        $body = implode("\n", ['t0', 't1', 't2']);
        $block = new TranscriptBlock(
            id: 'tr-timeout',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 3,
            text: $body,
            meta: ['tool_name' => 'bash', 'result' => $body, 'timed_out' => true, 'is_error' => true],
        );

        $output = $this->renderJoined($block, new TranscriptDisplayConfig(toolResultPreviewLines: 1));

        $this->assertStringContainsString('t2', $output);
        $this->assertStringNotContainsString('more line', $output);
    }

    public function testCollapsedFlagDoesNotDriveToolResultPreview(): void
    {
        $body = implode("\n", ['a', 'b', 'c', 'd']);
        $block = new TranscriptBlock(
            id: 'tr-collapsed',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 3,
            text: $body,
            meta: ['tool_name' => 'read', 'result' => $body, 'is_error' => false],
            collapsed: true,
        );

        $output = $this->renderJoined(
            $block,
            new TranscriptDisplayConfig(toolResultPreviewLines: 2),
            new TranscriptDisplayState(previewableBlocksExpanded: true),
        );

        $this->assertStringContainsString('d', $output);
        $this->assertStringNotContainsString('more line', $output);
    }


    public function testEditDiffLinesUseThemeDiffColors(): void
    {
        $palette = new ThemePalette('diff-test', [
            'diff_added' => 'green',
            'diff_removed' => 'red',
            'diff_context' => 'gray',
            'tool_title' => 'white',
        ]);
        $context = $this->context->withTheme(new DefaultTheme($palette));
        $patch = "+added\n-removed\n context";
        $block = new TranscriptBlock(
            id: 'tc-diff-colors',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 2,
            text: 'edit',
            meta: [
                'tool_name' => 'edit',
                'arguments' => ['path' => 'x.txt', 'patch' => $patch],
            ],
        );
        $renderer = new TranscriptBlockRenderer(factory: new TranscriptBlockWidgetFactory());
        $output = implode("\n", $renderer->renderBlock($block, $context));

        $this->assertStringContainsString("\x1b[", $output);
        $this->assertStringContainsString('+added', $output);
        $this->assertStringContainsString('-removed', $output);
    }

    public function testWriteToolCallRendersContentPreviewNotDiff(): void
    {
        $block = new TranscriptBlock(
            id: 'tc-write',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 2,
            text: 'write',
            meta: [
                'tool_name' => 'write',
                'arguments' => ['path' => 'notes.txt', 'content' => "line0\nline1"],
            ],
        );

        $output = $this->renderJoined($block);

        $this->assertStringContainsString('write', $output);
        $this->assertStringContainsString('path: notes.txt', $output);
        $this->assertStringContainsString('line0', $output);
        $this->assertStringNotContainsString('content: |', $output);
        $this->assertStringNotContainsString('+line0', $output);
    }

    public function testWriteMarkdownTargetProcessesMarkdown(): void
    {
        $block = new TranscriptBlock(
            id: 'tc-write-md',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 2,
            text: 'write',
            meta: [
                'tool_name' => 'write',
                'arguments' => ['path' => 'README.md', 'content' => '**bold** text'],
            ],
        );

        $output = $this->renderJoined($block);

        $this->assertStringContainsString('README.md', $output);
        $this->assertStringContainsString('bold', $output);
        $this->assertStringNotContainsString('**bold**', $output);
    }

    public function testWriteContentPreviewLimitedByDiffLines(): void
    {
        $lines = [];
        for ($i = 0; $i < 8; ++$i) {
            $lines[] = 'body'.$i;
        }
        $content = implode("\n", $lines);
        $block = new TranscriptBlock(
            id: 'tc-write-long',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 2,
            text: 'write',
            meta: [
                'tool_name' => 'write',
                'arguments' => ['path' => 'x.txt', 'content' => $content],
            ],
        );

        $output = $this->renderJoined(
            $block,
            new TranscriptDisplayConfig(diffPreviewLines: 3),
            new TranscriptDisplayState(previewableBlocksExpanded: false),
        );

        $this->assertStringContainsString('path: x.txt', $output);
        $this->assertStringContainsString('body0', $output);
        $this->assertStringNotContainsString('body7', $output);
        $this->assertStringContainsString('more line', $output);
    }

    public function testSuccessfulEditToolResultStripsUpdatedFileContext(): void
    {
        $result = "Stats: 1 file\n\nUpdated file context:\n→ 10: changed";
        $block = new TranscriptBlock(
            id: 'tr-edit-ok',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 3,
            text: $result,
            meta: ['tool_name' => 'edit', 'result' => $result, 'is_error' => false],
        );

        $output = $this->renderJoined($block);

        $this->assertStringContainsString('Stats: 1 file', $output);
        $this->assertStringNotContainsString('Updated file context', $output);
        $this->assertStringNotContainsString('→ 10:', $output);
    }

    public function testErrorEditToolResultKeepsFullDiagnostic(): void
    {
        $result = "fail\n\nUpdated file context:\n→ 1: x";
        $block = new TranscriptBlock(
            id: 'tr-edit-err',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 3,
            text: $result,
            meta: ['tool_name' => 'edit', 'result' => $result, 'is_error' => true],
        );

        $output = $this->renderJoined($block, new TranscriptDisplayConfig(toolResultPreviewLines: 1));

        $this->assertStringContainsString('Updated file context', $output);
        $this->assertStringContainsString('→ 1: x', $output);
    }

    public function testAskHumanToolCallSuppressed(): void
    {
        $block = new TranscriptBlock(
            id: 'tc-ask',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 1,
            text: 'ask_human',
            meta: [
                'tool_name' => 'ask_human',
                'arguments' => [
                    'kind' => 'interrupt',
                    'question_id' => 'ah_test',
                    'prompt' => 'What model?',
                    'schema' => ['type' => 'string'],
                ],
            ],
        );

        $output = $this->renderJoined($block);

        $this->assertSame('', $output);
    }

    public function testAskHumanSuccessfulToolResultSuppressed(): void
    {
        $json = '{"kind":"interrupt","question_id":"ah_test","prompt":"What model?","schema":{"type":"string"}}';
        $block = new TranscriptBlock(
            id: 'tr-ask',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 2,
            text: $json,
            meta: ['tool_name' => 'ask_human', 'result' => $json, 'is_error' => false],
        );

        $output = $this->renderJoined($block);

        $this->assertSame('', $output);
    }

    public function testAskHumanErrorToolResultRemainsVisible(): void
    {
        $body = 'Invalid question schema';
        $block = new TranscriptBlock(
            id: 'tr-ask-err',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 2,
            text: $body,
            meta: ['tool_name' => 'ask_human', 'result' => $body, 'is_error' => true],
        );

        $output = $this->renderJoined($block);

        $this->assertStringContainsString('ask_human', $output);
        $this->assertStringContainsString('Invalid question schema', $output);
    }

    public function testQuestionBlockRendersMarkdownBold(): void
    {
        $block = new TranscriptBlock(
            id: 'q-md',
            kind: TranscriptBlockKindEnum::Question,
            runId: 'r',
            seq: 3,
            text: 'Pick **one** option',
            meta: ['prompt' => 'Pick **one** option', 'status' => 'pending'],
        );

        $output = $this->renderJoined($block);

        $this->assertStringContainsString('?', $output);
        $this->assertStringContainsString('one', $output);
        $this->assertStringNotContainsString('**one**', $output);
    }

    public function testAnsweredQuestionHistoryShowsPromptAndAnswer(): void
    {
        $block = new TranscriptBlock(
            id: 'q-answered',
            kind: TranscriptBlockKindEnum::Question,
            runId: 'r',
            seq: 4,
            text: 'What model? → gpt-test',
            meta: [
                'prompt' => 'What model?',
                'status' => 'answered',
                'answer' => 'gpt-test',
            ],
        );

        $output = $this->renderJoined($block);

        $this->assertStringContainsString('What model?', $output);
        $this->assertStringContainsString('gpt-test', $output);
        $this->assertStringContainsString('→', $output);
    }

    public function testTranscriptBlockRenderCacheReusesUnchangedBlocks(): void
    {
        $block = new TranscriptBlock(
            id: 'cache-user',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'r',
            seq: 1,
            text: 'Cached hello',
        );
        $widget = new TranscriptBlockWidget();
        $widget->setBlocks([$block]);

        $first = $widget->render($this->context);
        $second = $widget->render($this->context);

        $this->assertSame($first, $second);
        $this->assertStringContainsString('Cached hello', implode("
", $second));
    }

    public function testTranscriptBlockRenderCacheInvalidatesOnTextChange(): void
    {
        $block = new TranscriptBlock(
            id: 'cache-stream',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'r',
            seq: 2,
            text: 'Part one',
            streaming: true,
        );
        $widget = new TranscriptBlockWidget();
        $widget->setBlocks([$block]);

        $first = $widget->render($this->context);

        $widget->setBlocks([
            new TranscriptBlock(
                id: 'cache-stream',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'r',
                seq: 2,
                text: 'Part one and more',
                streaming: true,
            ),
        ]);
        $second = $widget->render($this->context);

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('Part one and more', implode("
", $second));
    }

    public function testTranscriptBlockRenderCacheInvalidatesOnPreviewExpandedState(): void
    {
        $patchLines = [];
        for ($i = 0; $i < 6; ++$i) {
            $patchLines[] = '+line'.$i;
        }
        $patch = implode("
", array_merge(['---', '+++', '@@'], $patchLines));
        $block = new TranscriptBlock(
            id: 'cache-edit',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 3,
            text: 'edit',
            meta: [
                'tool_name' => 'edit',
                'arguments' => ['path' => '/tmp/test.md', 'patch' => $patch],
            ],
        );

        $collapsedWidget = new TranscriptBlockWidget(
            displayConfig: new TranscriptDisplayConfig(diffPreviewLines: 2),
            displayState: new TranscriptDisplayState(previewableBlocksExpanded: false),
        );
        $collapsedWidget->setBlocks([$block]);
        $collapsed = $collapsedWidget->render($this->context);

        $expandedWidget = new TranscriptBlockWidget(
            displayConfig: new TranscriptDisplayConfig(diffPreviewLines: 2),
            displayState: new TranscriptDisplayState(previewableBlocksExpanded: true),
        );
        $expandedWidget->setBlocks([$block]);
        $expanded = $expandedWidget->render($this->context);

        $collapsedPlain = preg_replace('/\[[0-9;]*m/', '', implode("
", $collapsed));
        $expandedPlain = preg_replace('/\[[0-9;]*m/', '', implode("
", $expanded));

        $this->assertStringNotContainsString('+line5', $collapsedPlain);
        $this->assertStringContainsString('+line5', $expandedPlain);
    }


    public function testAskHumanSequenceShowsOnlyQuestionNotToolPayload(): void
    {
        $prompt = 'Confirm **yes**?';
        $json = '{"kind":"interrupt","question_id":"ah_seq","prompt":"Confirm yes?"}';
        $blocks = [
            new TranscriptBlock(
                id: 'tc-ask-seq',
                kind: TranscriptBlockKindEnum::ToolCall,
                runId: 'r',
                seq: 1,
                text: 'ask_human',
                meta: [
                    'tool_name' => 'ask_human',
                    'arguments' => ['prompt' => $prompt, 'schema' => ['type' => 'string']],
                ],
            ),
            new TranscriptBlock(
                id: 'tr-ask-seq',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: 'r',
                seq: 2,
                text: $json,
                meta: ['tool_name' => 'ask_human', 'result' => $json, 'is_error' => false],
            ),
            new TranscriptBlock(
                id: 'q-seq',
                kind: TranscriptBlockKindEnum::Question,
                runId: 'r',
                seq: 3,
                text: $prompt,
                meta: ['prompt' => $prompt, 'status' => 'pending'],
            ),
        ];

        $widget = new TranscriptBlockWidget();
        $widget->setBlocks($blocks);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $widget->render($this->context)));

        $this->assertStringContainsString('Confirm', $plain);
        $this->assertStringNotContainsString('**yes**', $plain);
        $this->assertStringNotContainsString('kind":"interrupt', $plain);
        $this->assertStringNotContainsString('question_id', $plain);
        $this->assertStringNotContainsString('schema', $plain);
        $this->assertStringNotContainsString('ask_human', $plain);
    }

    public function testAnsweredQuestionRendersPromptAndAnswerAsSeparateSections(): void
    {
        $block = new TranscriptBlock(
            id: 'q-answered-sections',
            kind: TranscriptBlockKindEnum::Question,
            runId: 'r',
            seq: 4,
            text: 'What model? → gpt-test',
            meta: [
                'prompt' => 'What model?',
                'status' => 'answered',
                'answer' => 'gpt-test',
            ],
        );

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $this->renderWidgetLines([$block])));

        $this->assertStringContainsString('Human input answered', $plain);
        $this->assertStringContainsString('What model?', $plain);
        $this->assertStringContainsString('→ gpt-test', $plain);
        $this->assertStringNotContainsString('What model? → gpt-test', $plain);
    }

    public function testPendingQuestionShowsAwaitingAnswerAffordance(): void
    {
        $block = new TranscriptBlock(
            id: 'q-pending',
            kind: TranscriptBlockKindEnum::Question,
            runId: 'r',
            seq: 2,
            text: 'Pick **one**',
            meta: ['prompt' => 'Pick **one**', 'status' => 'pending'],
        );

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $this->renderWidgetLines([$block])));

        $this->assertStringContainsString('Human input required', $plain);
        $this->assertStringContainsString('awaiting answer', $plain);
        $this->assertStringContainsString('one', $plain);
    }

    public function testEmptyAssistantBeforeQuestionIsSuppressed(): void
    {
        $blocks = [
            new TranscriptBlock(
                id: 'as-empty',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'r',
                seq: 1,
                text: '',
            ),
            new TranscriptBlock(
                id: 'q-after',
                kind: TranscriptBlockKindEnum::Question,
                runId: 'r',
                seq: 2,
                text: 'Why?',
                meta: ['prompt' => 'Why?', 'status' => 'pending'],
            ),
        ];

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $this->renderWidgetLines($blocks)));

        $this->assertStringNotContainsString('[assistant]', $plain);
        $this->assertStringContainsString('Why?', $plain);
    }

    public function testNonEmptyAssistantBeforeQuestionStillRenders(): void
    {
        $blocks = [
            new TranscriptBlock(
                id: 'as-real',
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: 'r',
                seq: 1,
                text: 'Here is context.',
            ),
            new TranscriptBlock(
                id: 'q-after',
                kind: TranscriptBlockKindEnum::Question,
                runId: 'r',
                seq: 2,
                text: 'Continue?',
                meta: ['prompt' => 'Continue?', 'status' => 'pending'],
            ),
        ];

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $this->renderWidgetLines($blocks)));

        $this->assertStringContainsString('Here is context.', $plain);
        $this->assertStringContainsString('Continue?', $plain);
    }

    public function testAnsweredConfirmQuestionShowsYesNoInTranscript(): void
    {
        $block = new TranscriptBlock(
            id: 'q-confirm-yes',
            kind: TranscriptBlockKindEnum::Question,
            runId: 'r',
            seq: 5,
            text: 'Proceed? → yes',
            meta: [
                'prompt' => 'Proceed?',
                'status' => 'answered',
                'answer' => 'yes',
                'kind' => 'confirm',
            ],
        );

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $this->renderWidgetLines([$block])));

        $this->assertStringContainsString('Human input answered', $plain);
        $this->assertStringContainsString('Proceed?', $plain);
        $this->assertStringContainsString('→ yes', $plain);
    }


    public function testResumeSystemRowUsesMutedStyling(): void
    {
        $block = new TranscriptBlock(
            id: 'resume-1',
            kind: TranscriptBlockKindEnum::System,
            runId: 'r',
            seq: 1,
            text: 'Resumed run abc',
            meta: ['style' => 'muted', 'severity' => 'muted', 'category' => 'lifecycle'],
        );

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $this->renderWidgetLines([$block])));
        $this->assertStringContainsString('Resumed run abc', $plain);
        $this->assertStringContainsString('·', $plain);
        $this->assertStringNotContainsString('...', $plain);
    }

    public function testCompactionStartedLifecycleHasSingleGlyph(): void
    {
        $block = new TranscriptBlock(
            id: 'compact-glyph',
            kind: TranscriptBlockKindEnum::System,
            runId: 'r',
            seq: 2,
            text: 'Compacting conversation',
            meta: ['lifecycle' => 'compaction_started', 'category' => 'lifecycle', 'severity' => 'info'],
            streaming: true,
        );

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $this->renderWidgetLines([$block])));
        $this->assertSame(1, substr_count($plain, TranscriptGlyphs::GLYPH_COMPACTION_STARTED), 'Compaction started row must render exactly one ◐ glyph');
        $this->assertStringNotContainsString(TranscriptGlyphs::GLYPH_COMPACTION_STARTED.' '.TranscriptGlyphs::GLYPH_COMPACTION_STARTED, $plain);
    }

    public function testCompactionStartedStreamingSuffixNotDuplicatedInSourceText(): void
    {
        $block = new TranscriptBlock(
            id: 'compact-1',
            kind: TranscriptBlockKindEnum::System,
            runId: 'r',
            seq: 2,
            text: 'Compacting conversation',
            meta: ['lifecycle' => 'compaction_started', 'category' => 'lifecycle', 'severity' => 'info'],
            streaming: true,
        );

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $this->renderWidgetLines([$block])));
        $this->assertStringContainsString(TranscriptGlyphs::GLYPH_COMPACTION_STARTED, $plain);
        $this->assertStringContainsString('Compacting conversation', $plain);
        $this->assertStringContainsString('...', $plain);
        $this->assertStringNotContainsString('…...', $plain);
    }

    public function testCompactionCompletedLifecycleHasSingleGlyph(): void
    {
        $block = new TranscriptBlock(
            id: 'compact-done-glyph',
            kind: TranscriptBlockKindEnum::System,
            runId: 'r',
            seq: 3,
            text: 'Conversation compacted.',
            meta: ['lifecycle' => 'compaction_completed', 'category' => 'lifecycle', 'severity' => 'info'],
        );

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $this->renderWidgetLines([$block])));
        $this->assertSame(1, substr_count($plain, TranscriptGlyphs::GLYPH_COMPACTION_COMPLETED), 'Compaction completed row must render exactly one ⧉ glyph');
        $this->assertStringNotContainsString(TranscriptGlyphs::GLYPH_COMPACTION_COMPLETED.' '.TranscriptGlyphs::GLYPH_COMPACTION_COMPLETED, $plain);
    }

    public function testToolCallArgumentKeyValueAnsiDiffers(): void
    {
        $block = new TranscriptBlock(
            id: 'tool-args',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 4,
            text: 'read',
            meta: [
                'tool_name' => 'read',
                'arguments' => ['path' => '/tmp/x.txt', 'limit' => 10],
            ],
        );

        $context = $this->context->withTheme(new DefaultTheme(new ThemePalette('arg-test', [
            ThemeColorEnum::ToolTitle->value => 'green',
            ThemeColorEnum::ToolArgumentKey->value => 'cyan',
            ThemeColorEnum::ToolArgumentValue->value => 'yellow',
        ])));
        $renderer = new TranscriptBlockRenderer(factory: new TranscriptBlockWidgetFactory());
        $raw = implode("\n", $renderer->renderBlock($block, $context));
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $raw);
        $this->assertStringContainsString('path:', $plain);
        $this->assertMatchesRegularExpression('/\x1b\[[0-9;]*m/', $raw);
    }

    public function testViewImageToolCallAndResultCompactMetadata(): void
    {
        $call = new TranscriptBlock(
            id: 'vi-call',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 5,
            text: 'view_image',
            meta: ['tool_name' => 'view_image', 'arguments' => ['path' => 'img.png']],
        );
        $result = new TranscriptBlock(
            id: 'vi-res',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 6,
            text: 'view_image',
            meta: [
                'tool_name' => 'view_image',
                'result' => json_encode([
                    'type' => 'view_image',
                    'path' => '/tmp/img.png',
                    'media_type' => 'image/png',
                    'width' => 100,
                    'height' => 50,
                    'bytes' => 1234,
                ], JSON_THROW_ON_ERROR),
                'is_error' => false,
            ],
        );

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $this->renderWidgetLines([$call, $result])));
        $this->assertStringContainsString('path: img.png', $plain);
        $this->assertStringContainsString('media: image/png', $plain);
        $this->assertStringContainsString('size: 100x50', $plain);
        $this->assertStringContainsString('bytes: 1234', $plain);
        $this->assertStringNotContainsString('attachment_refs', $plain);
    }

    public function testViewImageFailedResultShowsRawErrorText(): void
    {
        $block = new TranscriptBlock(
            id: 'vi-err',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'r',
            seq: 8,
            text: 'view_image',
            meta: [
                'tool_name' => 'view_image',
                'result' => 'Image path does not exist: /missing.png',
                'is_error' => true,
            ],
        );

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $this->renderWidgetLines([$block])));
        $this->assertStringContainsString('Image path does not exist: /missing.png', $plain);
        $this->assertStringNotContainsString('(image metadata)', $plain);
    }

    public function testViewImageStreamingToolCallSuffixAppliedOnce(): void
    {
        $block = new TranscriptBlock(
            id: 'vi-stream',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'r',
            seq: 7,
            text: 'view_image',
            meta: ['tool_name' => 'view_image', 'arguments' => ['path' => 'img.png']],
            streaming: true,
        );

        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', implode("\n", $this->renderWidgetLines([$block])));
        $this->assertStringContainsString('view_image...', $plain);
        $this->assertStringNotContainsString('......', $plain);
    }

    private function renderWidgetLines(array $blocks): array
    {
        $widget = new TranscriptBlockWidget();
        $widget->setBlocks($blocks);

        return $widget->render($this->context);
    }


}
