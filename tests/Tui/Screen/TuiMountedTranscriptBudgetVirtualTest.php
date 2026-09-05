<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\SubagentProgressCardWidget;
use Ineersa\Tui\Transcript\ThemeStyleSheetFactory;
use Ineersa\Tui\Transcript\TranscriptClippedRowsWidget;
use Ineersa\Tui\Transcript\TranscriptMountedWidget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\MarkdownWidget;

/**
 * Virtual proof for the mounted transcript rendered-row budget and themed attach lifecycle.
 *
 * Test thesis: only the latest budgeted wrapped rows stay mounted under live geometry;
 * oversized boundary nodes become clipped leaves; Markdown/subagent styles resolve from
 * the live WidgetContext stylesheet, not Symfony defaults. Detached setBlocks stays lazy.
 * Row overflow is built from a few multi-line plain-text blocks so the case stays ≤10s.
 */
final class TuiMountedTranscriptBudgetVirtualTest extends TestCase
{
    private const string SESSION_ID = 'virtual-mounted-budget';

    #[Test]
    public function testMountedTailKeepsOnlyBudgetedRowsAndClipsBoundaryNode(): void
    {
        $theme = new DefaultTheme(new ThemePalette('budget-theme', []));
        $terminal = new VirtualTerminal(columns: 40, rows: 20);
        $tui = new Tui(terminal: $terminal);
        $transcript = new TranscriptMountedWidget(theme: $theme);

        $blocks = [
            $this->plainLinesBlock('early', 1, 700, 'early'),
            $this->plainLinesBlock('mid', 2, 700, 'mid'),
            $this->plainLinesBlock('late', 3, 700, 'late'),
        ];
        $transcript->setBlocks($blocks);
        $this->assertSame([], $transcript->all(), 'Detached setBlocks must stay lazy until attached geometry exists');

        $tui->add($transcript);

        $tui->requestRender(force: true);
        $tui->processRender();

        $children = $transcript->all();
        $this->assertCount(3, $children, 'Tail keeps late/mid and clips the oldest overflowing block');
        $this->assertGreaterThan(0, \count($children));
        $output = $terminal->getOutput();
        $this->assertStringContainsString('late-700', $output);
        $this->assertDoesNotMatchRegularExpression('/early-1(?:\\r|\\n|$)/', $output);
        $this->assertInstanceOf(TranscriptClippedRowsWidget::class, $children[0]);

        $mountedRows = 0;
        foreach ($children as $child) {
            if ($child instanceof TranscriptClippedRowsWidget) {
                $mountedRows += \count($child->render(new RenderContext(40, 20)));
                continue;
            }
            $mountedRows += \count($child->getRenderCache(40, 20) ?? []);
        }
        $this->assertLessThanOrEqual(TranscriptMountedWidget::RENDERED_ROW_BUDGET, $mountedRows);

        $oversized = $this->plainLinesBlock(
            'giant',
            100,
            TranscriptMountedWidget::RENDERED_ROW_BUDGET + 40,
            'giant',
        );
        $transcript->setBlocks([$oversized]);
        $tui->requestRender(force: true);
        $tui->processRender();

        $children = $transcript->all();
        $this->assertCount(1, $children);
        $this->assertInstanceOf(TranscriptClippedRowsWidget::class, $children[0]);
        $this->assertCount(
            TranscriptMountedWidget::RENDERED_ROW_BUDGET,
            $children[0]->render(new RenderContext(40, 20)),
        );
        $this->assertStringContainsString('giant-'.(TranscriptMountedWidget::RENDERED_ROW_BUDGET + 40), $terminal->getOutput());
        $this->assertDoesNotMatchRegularExpression('/giant-1(?:\\r|\\n|$)/', $terminal->getOutput());
    }

    #[Test]
    public function testBudgetedMountPreservesThemedMarkdownAndSubagentBorders(): void
    {
        $palette = new ThemePalette('budget-theme-colors', [
            ThemeColorEnum::MarkdownHeading->value => 'bright_magenta',
            ThemeColorEnum::MarkdownCode->value => 'bright_yellow',
            ThemeColorEnum::BorderAccent->value => 'bright_cyan',
            ThemeColorEnum::Success->value => 'bright_green',
        ]);
        $theme = new DefaultTheme($palette);
        $terminal = new VirtualTerminal(columns: 100, rows: 30);
        $tui = new Tui(terminal: $terminal);
        $styles = new ThemeStyleSheetFactory();
        $tui->addStyleSheet($styles->createMarkdown($palette));
        $tui->addStyleSheet($styles->createSubagentProgressCard($palette));

        $transcript = new TranscriptMountedWidget(theme: $theme);
        $tui->add($transcript);
        $transcript->setBlocks([
            new TranscriptBlock(
                id: 'system-md-budget',
                kind: TranscriptBlockKindEnum::System,
                runId: self::SESSION_ID,
                seq: 1,
                text: "# Heading\n\nUse `code`.\n",
                meta: ['style' => 'markdown'],
            ),
            new TranscriptBlock(
                id: 'tool-progress-budget',
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: self::SESSION_ID,
                seq: 2,
                text: 'progress',
                meta: [
                    'tool_name' => 'agent',
                    'result' => 'progress',
                    'subagent_progress' => new SubagentProgressSingleSnapshotDTO(
                        mode: 'single',
                        status: 'completed',
                        agentName: 'fork',
                        artifactId: 'artifact-budget',
                        agentRunId: 'child-budget',
                        taskSummary: 'Budget theme proof',
                        model: 'test/model',
                        reasoning: 'medium',
                    ),
                ],
            ),
        ]);

        $tui->requestRender(force: true);
        $tui->processRender();

        $markdown = null;
        $card = null;
        foreach ($this->walk($transcript) as $widget) {
            if (null === $markdown && $widget instanceof MarkdownWidget) {
                $markdown = $widget;
            }
            if (null === $card && $widget instanceof SubagentProgressCardWidget) {
                $card = $widget;
            }
        }

        $this->assertInstanceOf(MarkdownWidget::class, $markdown);
        $this->assertNotNull($markdown->getContext());
        $headingStyle = $markdown->getContext()->resolveElement($markdown, 'heading');
        $this->assertNotNull($headingStyle->getColor());
        $this->assertSame([255, 0, 255], array_values($headingStyle->getColor()->toRgb()));

        $this->assertInstanceOf(SubagentProgressCardWidget::class, $card);
        $this->assertNotNull($card->getContext());
        $borderStyle = $card->getContext()->resolveElement($card, 'border');
        $this->assertNotNull($borderStyle->getColor());
        $this->assertSame([0, 255, 255], array_values($borderStyle->getColor()->toRgb()));
    }

    private function plainLinesBlock(string $id, int $seq, int $lineCount, string $prefix): TranscriptBlock
    {
        return new TranscriptBlock(
            id: $id,
            kind: TranscriptBlockKindEnum::System,
            runId: self::SESSION_ID,
            seq: $seq,
            text: implode("\n", array_map(
                static fn (int $n): string => $prefix.'-'.$n,
                range(1, $lineCount),
            )),
        );
    }

    /**
     * @return \Generator<int, \Symfony\Component\Tui\Widget\AbstractWidget>
     */
    private function walk(\Symfony\Component\Tui\Widget\AbstractWidget $root): \Generator
    {
        yield $root;
        if ($root instanceof \Symfony\Component\Tui\Widget\ContainerWidget) {
            foreach ($root->all() as $child) {
                yield from $this->walk($child);
            }
        }
    }
}
