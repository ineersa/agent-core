<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\MarkdownThemeStyleSheetFactory;
use Ineersa\Tui\Transcript\TranscriptMountedWidget;
use Ineersa\Tui\Transcript\TranscriptVisualNodeWidget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\MarkdownWidget;

/**
 * Virtual proof for the production mounted transcript subtree.
 *
 * Test thesis: output-only product tests can stay green while Markdown widgets
 * remain detached, stylesheet selectors stay inactive, or reconciliation recreates
 * visual nodes. These cases prove live WidgetContext theming, identity-preserving
 * streaming/tool reconciliation, and granular tail append/removal on the real
 * mounted path.
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
        $tui->addStyleSheet((new MarkdownThemeStyleSheetFactory())->create($theme));

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
        $this->assertInstanceOf(TranscriptVisualNodeWidget::class, $assistantWrapper);
        $this->assertInstanceOf(TranscriptVisualNodeWidget::class, $toolWrapper);
        $assistantMarkdown = $assistantWrapper->content();
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
        $this->assertSame($assistantMarkdown, $assistantWrapper->content(), 'Assistant MarkdownWidget instance must be preserved across streaming updates');
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
        $this->assertStringContainsString('file contents', $text);
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
        $this->assertInstanceOf(TranscriptVisualNodeWidget::class, $userWrapper);
        $this->assertInstanceOf(TranscriptVisualNodeWidget::class, $assistantWrapper);

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
        $this->assertInstanceOf(TranscriptVisualNodeWidget::class, $afterAppend[2]);

        $transcript->setBlocks([$user, $extra]);
        $afterRemove = $transcript->all();
        // Removing the middle assistant leaves user + later assistant; relative survivor order
        // is preserved so granular path can drop the middle node without whole-subtree rebuild.
        $this->assertCount(2, $afterRemove);
        $this->assertSame($userWrapper, $afterRemove[0], 'User wrapper must survive middle removal');
        $this->assertSame($afterAppend[2], $afterRemove[1], 'Tail-appended wrapper must survive middle removal');
        $this->assertNotContains($assistantWrapper, $afterRemove, 'Removed assistant wrapper must be detached');
    }

    /**
     * @return list<MarkdownWidget>
     */
    private function findMarkdownWidgets(TranscriptMountedWidget $transcript): array
    {
        $found = [];
        foreach ($transcript->all() as $child) {
            if (!$child instanceof TranscriptVisualNodeWidget) {
                continue;
            }
            $content = $child->content();
            if ($content instanceof MarkdownWidget) {
                $found[] = $content;
            }
        }

        return $found;
    }
}
