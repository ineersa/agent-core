<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\StreamingMarkdownTranscriptWidget;
use Ineersa\Tui\Transcript\ThemeStyleSheetFactory;
use Ineersa\Tui\Transcript\ToolExchangeTranscriptWidget;
use Ineersa\Tui\Transcript\TranscriptMountedWidget;
use Ineersa\Tui\Transcript\TranscriptVisualNode;
use Ineersa\Tui\Transcript\TranscriptVisualProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Virtual proof for the production mounted transcript subtree.
 *
 * Test thesis: output-only product tests can stay green while Markdown widgets
 * remain detached, stylesheet selectors stay inactive, or reconciliation recreates
 * visual nodes. These cases prove live WidgetContext theming, identity-preserving
 * streaming/tool reconciliation, granular tail append/removal, content-only tail
 * stream identity preservation, and mid-list generic TextWidget update order.
 * Patch contract for pure stream is proven on TranscriptVisualProjector directly.
 */
final class TuiMountedTranscriptVirtualTest extends TestCase
{
    private const string SESSION_ID = 'virtual-mounted-transcript';

    #[Test]
    public function testMountedMarkdownReceivesLiveContextAndThemedSubElements(): void
    {
        $palette = new ThemePalette('mounted-markdown-theme', [
            ThemeColorEnum::MarkdownHeading->value => 'bright_magenta',
            ThemeColorEnum::MarkdownLink->value => 'bright_cyan',
            ThemeColorEnum::MarkdownCode->value => 'bright_yellow',
            ThemeColorEnum::MarkdownListBullet->value => 'bright_green',
            ThemeColorEnum::MarkdownQuote->value => 'bright_blue',
            ThemeColorEnum::MarkdownHr->value => 'bright_white',
        ]);
        $theme = new DefaultTheme($palette);
        $terminal = new VirtualTerminal(columns: 100, rows: 30);
        $tui = new Tui(terminal: $terminal);
        $tui->addStyleSheet((new ThemeStyleSheetFactory())->createMarkdown($palette));

        $transcript = new TranscriptMountedWidget(theme: $theme);
        $tui->add($transcript);
        // System markdown path has no role glyph prefix, so ATX headings remain at column 0
        // and CommonMark can parse them. This isolates WidgetContext stylesheet resolution.
        $transcript->setBlocks([
            new TranscriptBlock(
                id: 'system-md-1',
                kind: TranscriptBlockKindEnum::System,
                runId: self::SESSION_ID,
                seq: 1,
                text: "# Heading\n\nSee [docs](https://example.com) and `code`.\n\n- item one\n\n> quote line\n\n---\n",
                meta: ['style' => 'markdown'],
            ),
        ]);

        $tui->requestRender(force: true);
        $tui->processRender();

        $markdown = $this->findMarkdownWidgets($transcript);
        $this->assertCount(1, $markdown, 'Expected one mounted MarkdownWidget child');
        $md = $markdown[0];
        $this->assertNotNull($md->getContext(), 'Mounted MarkdownWidget must receive live WidgetContext');

        $headingStyle = $md->getContext()->resolveElement($md, 'heading');
        $linkStyle = $md->getContext()->resolveElement($md, 'link');
        $codeStyle = $md->getContext()->resolveElement($md, 'code');
        $this->assertNotNull($headingStyle->getColor());
        $this->assertSame([255, 0, 255], array_values($headingStyle->getColor()->toRgb()));
        $this->assertTrue($headingStyle->getBold());
        $this->assertNotNull($linkStyle->getColor());
        $this->assertSame([0, 255, 255], array_values($linkStyle->getColor()->toRgb()));
        $this->assertTrue($linkStyle->getUnderline(), 'Link style must keep underline while applying theme color');
        $this->assertNotNull($codeStyle->getColor());
        $this->assertSame([255, 255, 0], array_values($codeStyle->getColor()->toRgb()));

        $output = $terminal->getOutput();
        $this->assertStringContainsString('Heading', $output);
        $this->assertStringContainsString('docs', $output);
        $this->assertStringContainsString('code', $output);
        $this->assertStringNotContainsString('# Heading', $output, 'Rendered markdown must not keep raw heading delimiters');
        $this->assertStringNotContainsString('[docs](https://example.com)', $output, 'Rendered markdown must not keep raw link delimiters');
        $this->assertStringNotContainsString('`code`', $output, 'Rendered markdown must not keep raw code delimiters');
    }

    #[Test]
    public function testStreamingAndToolResultPreserveWrapperAndMarkdownIdentity(): void
    {
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        $transcript = new TranscriptMountedWidget(theme: $theme);

        $assistantStreaming = new TranscriptBlock(
            id: 'assistant-stream',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: self::SESSION_ID,
            seq: 1,
            text: 'Hello',
            streaming: true,
        );
        $toolCall = new TranscriptBlock(
            id: 'tool-call-1',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: self::SESSION_ID,
            seq: 2,
            text: 'read',
            meta: [
                'tool_name' => 'read',
                'tool_call_id' => 'call-stable-1',
                'arguments' => ['path' => './README.md'],
            ],
            streaming: true,
        );
        $transcript->setBlocks([$assistantStreaming, $toolCall]);

        $childrenAfterMount = $transcript->all();
        $this->assertCount(2, $childrenAfterMount);
        $assistantWrapper = $childrenAfterMount[0];
        $toolWrapper = $childrenAfterMount[1];
        $this->assertInstanceOf(StreamingMarkdownTranscriptWidget::class, $assistantWrapper);
        $this->assertInstanceOf(ToolExchangeTranscriptWidget::class, $toolWrapper);
        $assistantMarkdown = $this->streamingMarkdown($assistantWrapper);
        $this->assertInstanceOf(MarkdownWidget::class, $assistantMarkdown);

        $assistantUpdated = $assistantStreaming->with(text: 'Hello world', streaming: true);
        $toolResult = new TranscriptBlock(
            id: 'tool-result-1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: self::SESSION_ID,
            seq: 3,
            text: "file contents\nline two",
            meta: [
                'tool_name' => 'read',
                'tool_call_id' => 'call-stable-1',
                'is_error' => false,
            ],
        );
        $transcript->setBlocks([
            $assistantUpdated->with(streaming: false),
            $toolCall->with(streaming: false),
            $toolResult,
        ]);

        $childrenAfterUpdate = $transcript->all();
        $this->assertCount(2, $childrenAfterUpdate, 'Tool call+result must remain one visual exchange node');
        $this->assertSame($assistantWrapper, $childrenAfterUpdate[0], 'Assistant visual wrapper identity must be stable');
        $this->assertSame($toolWrapper, $childrenAfterUpdate[1], 'Tool exchange wrapper identity must be stable across result arrival');
        $this->assertSame($assistantMarkdown, $this->streamingMarkdown($assistantWrapper), 'Assistant MarkdownWidget instance must be preserved across streaming updates');
        $this->assertStringContainsString('Hello world', $assistantMarkdown->getText());

        // Render through ChatScreen production path as product smoke for visible result text.
        $harness = new VirtualTuiHarness(sessionId: self::SESSION_ID);
        $harness->screen()->setTranscriptBlocks([
            $assistantUpdated->with(streaming: false),
            $toolCall->with(streaming: false),
            $toolResult,
        ]);
        $harness->screen()->setWorkingVisible(false);
        $text = $harness->plainScreenText();
        $this->assertStringContainsString('Hello world', $text);
        $this->assertStringContainsString('read', $text);
        $this->assertStringContainsString('./README.md', $text);
        $this->assertStringNotContainsString('file contents', $text);
    }

    #[Test]
    public function testAssistantContentUpdateAdjacentToExchangeKeepsSemanticExchange(): void
    {
        // Thesis: content update of an assistant that neighbors a completed tool exchange
        // must not reclassify the exchange secondary as standalone GENERIC, remount, or
        // reorder the semantic ToolExchangeTranscriptWidget.
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        $transcript = new TranscriptMountedWidget(theme: $theme);

        $toolCall = new TranscriptBlock(
            id: 'tool-call-adj',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: self::SESSION_ID,
            seq: 1,
            text: 'read',
            meta: [
                'tool_name' => 'read',
                'tool_call_id' => 'call-adjacent-1',
                'arguments' => ['path' => './target.txt'],
            ],
        );
        $toolResult = new TranscriptBlock(
            id: 'tool-result-adj',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: self::SESSION_ID,
            seq: 2,
            text: "rewound contents\nline two",
            meta: [
                'tool_name' => 'read',
                'tool_call_id' => 'call-adjacent-1',
                'is_error' => false,
            ],
        );
        $assistantStreaming = new TranscriptBlock(
            id: 'assistant-after-exchange',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: self::SESSION_ID,
            seq: 3,
            text: 'partial after tool',
            streaming: true,
        );
        $transcript->setBlocks([$toolCall, $toolResult, $assistantStreaming]);

        $before = $transcript->all();
        $this->assertCount(2, $before, 'Call+result collapse to one exchange before assistant');
        $exchangeWrapper = $before[0];
        $assistantWrapper = $before[1];
        $this->assertInstanceOf(ToolExchangeTranscriptWidget::class, $exchangeWrapper);
        $this->assertInstanceOf(StreamingMarkdownTranscriptWidget::class, $assistantWrapper);
        $exchangeNodeBefore = $this->semanticNode($exchangeWrapper);
        $this->assertNotNull($exchangeNodeBefore);
        $this->assertSame(TranscriptVisualNode::KIND_TOOL_EXCHANGE, $exchangeNodeBefore->kind);
        $this->assertSame($toolResult, $exchangeNodeBefore->secondary);

        $assistantUpdated = $assistantStreaming->with(text: 'partial after tool more tokens');
        $transcript->applyChangeSet(TranscriptChangeSet::incremental([$assistantUpdated]));

        $after = $transcript->all();
        $this->assertCount(2, $after, 'Child count must stay exchange + assistant');
        $this->assertSame($exchangeWrapper, $after[0], 'Exchange widget identity must survive adjacent stream');
        $this->assertSame($assistantWrapper, $after[1], 'Assistant widget identity must survive stream');
        $exchangeNodeAfter = $this->semanticNode($exchangeWrapper);
        $this->assertNotNull($exchangeNodeAfter);
        $this->assertSame(TranscriptVisualNode::KIND_TOOL_EXCHANGE, $exchangeNodeAfter->kind);
        $this->assertSame($toolResult, $exchangeNodeAfter->secondary, 'Exchange secondary must remain the same ToolResult');
        $this->assertSame($toolCall, $exchangeNodeAfter->primary);
        $md = $this->streamingMarkdown($assistantWrapper);
        $this->assertInstanceOf(MarkdownWidget::class, $md);
        $this->assertStringContainsString('partial after tool more tokens', $md->getText());
    }

    #[Test]
    public function testTailAppendAndRemovalKeepExistingWrapperIdentity(): void
    {
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        $transcript = new TranscriptMountedWidget(theme: $theme);

        $user = new TranscriptBlock(
            id: 'user-1',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: self::SESSION_ID,
            seq: 1,
            text: 'first',
        );
        $assistant = new TranscriptBlock(
            id: 'assistant-1',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: self::SESSION_ID,
            seq: 2,
            text: 'second',
        );
        $transcript->setBlocks([$user, $assistant]);

        $before = $transcript->all();
        $this->assertCount(2, $before);
        $userWrapper = $before[0];
        $assistantWrapper = $before[1];
        $this->assertInstanceOf(StreamingMarkdownTranscriptWidget::class, $userWrapper);
        $this->assertInstanceOf(StreamingMarkdownTranscriptWidget::class, $assistantWrapper);

        $extra = new TranscriptBlock(
            id: 'assistant-2',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: self::SESSION_ID,
            seq: 3,
            text: 'third',
        );
        $transcript->setBlocks([$user, $assistant, $extra]);
        $afterAppend = $transcript->all();
        $this->assertCount(3, $afterAppend);
        $this->assertSame($userWrapper, $afterAppend[0], 'Existing user wrapper must survive tail append');
        $this->assertSame($assistantWrapper, $afterAppend[1], 'Existing assistant wrapper must survive tail append');
        $this->assertNotSame($assistantWrapper, $afterAppend[2]);
        $this->assertInstanceOf(StreamingMarkdownTranscriptWidget::class, $afterAppend[2]);

        $transcript->setBlocks([$user, $extra]);
        $afterRemove = $transcript->all();
        // Removing the middle assistant leaves user + later assistant; relative survivor order
        // is preserved so granular path can drop the middle node without whole-subtree rebuild.
        $this->assertCount(2, $afterRemove);
        $this->assertSame($userWrapper, $afterRemove[0], 'User wrapper must survive middle removal');
        $this->assertSame($afterAppend[2], $afterRemove[1], 'Tail-appended wrapper must survive middle removal');
        $this->assertNotContains($assistantWrapper, $afterRemove, 'Removed assistant wrapper must be detached');
    }

    #[Test]
    public function testTailStreamKeepsHistoricalSemanticIdentityWithoutTestOnlyPatchApi(): void
    {
        // Thesis: ordinary tail streaming updates must not rebuild finalized historical
        // semantic wrappers or their Markdown content instances. Patch scope is asserted
        // on TranscriptVisualProjector (production contract), not a test-only mount API.
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        $transcript = new TranscriptMountedWidget(theme: $theme);

        $history = new TranscriptBlock(
            id: 'history-user',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: self::SESSION_ID,
            seq: 1,
            text: 'finalized history that must not be rehashed',
        );
        $streaming = new TranscriptBlock(
            id: 'stream-assistant',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: self::SESSION_ID,
            seq: 2,
            text: 'partial',
            streaming: true,
        );
        $transcript->setBlocks([$history, $streaming]);

        $children = $transcript->all();
        $this->assertCount(2, $children);
        $historyWrapper = $children[0];
        $streamWrapper = $children[1];
        $this->assertInstanceOf(StreamingMarkdownTranscriptWidget::class, $historyWrapper);
        $this->assertInstanceOf(StreamingMarkdownTranscriptWidget::class, $streamWrapper);
        $historyMarkdown = $this->streamingMarkdown($historyWrapper);
        $streamMarkdown = $this->streamingMarkdown($streamWrapper);
        $this->assertInstanceOf(MarkdownWidget::class, $historyMarkdown);
        $this->assertInstanceOf(MarkdownWidget::class, $streamMarkdown);
        $historyNode = $this->semanticNode($historyWrapper);
        $this->assertNotNull($historyNode);

        $streamed = $streaming->with(text: 'partial more tokens');
        // Same object identity for history; new object for streaming tail.
        $transcript->applyChangeSet(TranscriptChangeSet::incremental([$streamed]));

        $after = $transcript->all();
        $this->assertCount(2, $after);
        $this->assertSame($historyWrapper, $after[0], 'Finalized history wrapper must keep identity on tail stream');
        $this->assertSame($streamWrapper, $after[1], 'Streaming wrapper must keep identity on tail stream');
        $this->assertSame($historyMarkdown, $this->streamingMarkdown($historyWrapper), 'Finalized history Markdown instance must not rebuild');
        $this->assertSame($streamMarkdown, $this->streamingMarkdown($streamWrapper), 'Streaming Markdown instance must mutate in place');
        $this->assertSame($historyNode, $this->semanticNode($historyWrapper), 'History visual node sources unchanged (object identity skip)');
        $this->assertSame($history, $this->semanticNode($historyWrapper)?->primary);
        $this->assertStringContainsString('partial more tokens', $streamMarkdown->getText());
    }

    #[Test]
    public function testMiddleGenericUpdateKeepsOrderAndMutatesTextInPlace(): void
    {
        // Thesis: mid-list KIND_GENERIC TextWidget content change must not append a fresh
        // widget at the end (order corruption). setText in place preserves sibling order.
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        $transcript = new TranscriptMountedWidget(theme: $theme);

        $user = new TranscriptBlock(
            id: 'user-1',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: self::SESSION_ID,
            seq: 1,
            text: 'prompt',
        );
        $error = new TranscriptBlock(
            id: 'error-mid',
            kind: TranscriptBlockKindEnum::Error,
            runId: self::SESSION_ID,
            seq: 2,
            text: 'first error text',
        );
        $assistant = new TranscriptBlock(
            id: 'assistant-tail',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: self::SESSION_ID,
            seq: 3,
            text: 'tail answer',
        );
        $transcript->setBlocks([$user, $error, $assistant]);

        $before = $transcript->all();
        $this->assertCount(3, $before);
        $this->assertInstanceOf(StreamingMarkdownTranscriptWidget::class, $before[0]);
        $this->assertInstanceOf(TextWidget::class, $before[1], 'Error block mounts as native TextWidget');
        $this->assertInstanceOf(StreamingMarkdownTranscriptWidget::class, $before[2]);
        $errorWidget = $before[1];
        $this->assertInstanceOf(TextWidget::class, $errorWidget);
        $userWidget = $before[0];
        $assistantWidget = $before[2];

        $updatedError = $error->with(text: 'updated middle error');
        $transcript->applyChangeSet(TranscriptChangeSet::incremental([$updatedError]));

        $after = $transcript->all();
        $this->assertCount(3, $after, 'Middle generic update must not change child count');
        $this->assertSame($userWidget, $after[0], 'Leading markdown identity preserved');
        $this->assertSame($errorWidget, $after[1], 'Middle TextWidget identity must be preserved (in-place setText)');
        $this->assertSame($assistantWidget, $after[2], 'Trailing markdown must stay after middle generic');
        $this->assertStringContainsString('updated middle error', $errorWidget->getText());
        $this->assertStringNotContainsString('first error text', $errorWidget->getText());

        // Production projector contract for pure generic content update is content-only.
        $projector = new TranscriptVisualProjector();
        $projector->replaceAll([$user, $error, $assistant]);
        $patch = $projector->applyChangeSet(TranscriptChangeSet::incremental([$updatedError]));
        $this->assertTrue($patch->isContentOnly());
        $this->assertNull($patch->order);
        $this->assertCount(1, $patch->upserts);
        $this->assertSame('error-mid', $patch->upserts[0]->key);
        $this->assertSame([], $patch->removals);
    }

    #[Test]
    public function testWrongKindOnStableKeyRecreatesSemanticWidget(): void
    {
        // Thesis: re-binding an existing stable key to a different visual kind must not
        // apply() the wrong kind onto the old widget (semantic apply() rejects it); the
        // mounted adapter must re-create the widget for the new kind and bind the node.
        // Key collision: a plain block id shaped like an exchange key maps to the same
        // stable key as a tool call carrying that tool_call_id.
        $theme = new DefaultTheme(VirtualTuiHarness::defaultVirtualPalette());
        $transcript = new TranscriptMountedWidget(theme: $theme);

        $user = new TranscriptBlock(
            id: 'exchange:race-1',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: self::SESSION_ID,
            seq: 1,
            text: 'prompt',
        );
        $transcript->setBlocks([$user]);
        $before = $transcript->all();
        $this->assertCount(1, $before);
        $this->assertInstanceOf(StreamingMarkdownTranscriptWidget::class, $before[0]);

        $toolCall = new TranscriptBlock(
            id: 'tool-race-1',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: self::SESSION_ID,
            seq: 2,
            text: 'read',
            meta: [
                'tool_name' => 'read',
                'tool_call_id' => 'race-1',
                'arguments' => ['path' => './README.md'],
            ],
        );
        $transcript->setBlocks([$toolCall]);

        $after = $transcript->all();
        $this->assertCount(1, $after, 'Wrong-kind replacement must keep one mounted child');
        $this->assertNotSame($before[0], $after[0], 'Wrong-kind stable key must re-create the widget, not reuse the old one');
        $this->assertInstanceOf(ToolExchangeTranscriptWidget::class, $after[0], 'Stable key re-bound to tool exchange must mount a tool-exchange widget');
        $this->assertSame(TranscriptVisualNode::KIND_TOOL_EXCHANGE, $this->semanticNode($after[0])?->kind, 'Re-created widget must receive its node data via the semantic apply contract');
    }

    /**
     * @return list<MarkdownWidget>
     */
    private function semanticNode(object $widget): ?TranscriptVisualNode
    {
        return (new \ReflectionProperty($widget, 'node'))->getValue($widget);
    }

    private function streamingMarkdown(StreamingMarkdownTranscriptWidget $widget): ?MarkdownWidget
    {
        return (new \ReflectionProperty($widget, 'markdown'))->getValue($widget);
    }

    private function findMarkdownWidgets(TranscriptMountedWidget $transcript): array
    {
        $found = [];
        foreach ($transcript->all() as $child) {
            if ($child instanceof StreamingMarkdownTranscriptWidget) {
                $md = $this->streamingMarkdown($child);
                if ($md instanceof MarkdownWidget) {
                    $found[] = $md;
                }
            }
        }

        return $found;
    }
}
