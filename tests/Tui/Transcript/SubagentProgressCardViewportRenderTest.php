<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

#[AllowMockObjectsWithoutExpectations]
final class SubagentProgressCardViewportRenderTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testParallelProgressUpdatesStayDifferentialBelowNativeScrollback(): void
    {
        $rows = 40;
        $output = new VirtualTerminal(columns: 120, rows: $rows);
        $dispatcher = new EventDispatcher();
        $terminal = $this->createStub(TerminalInterface::class);
        $terminal->method('getEventDispatcher')->willReturn($dispatcher);
        $terminal->method('getColumns')->willReturn(120);
        $terminal->method('getRows')->willReturn($rows);
        $terminal->method('isVirtual')->willReturn(false);
        $terminal->method('write')->willReturnCallback($output->write(...));
        $terminal->method('showCursor')->willReturnCallback($output->showCursor(...));
        $terminal->method('hideCursor')->willReturnCallback($output->hideCursor(...));

        $tui = new Tui(terminal: $terminal, eventDispatcher: $dispatcher);
        $screen = new ChatScreen(new DefaultTheme(new ThemePalette('test', [])), 'compact-progress', new PromptEditor());
        $screen->mount($tui);
        $transcript = (new TranscriptBlockFactory())->system(
            runId: 'compact-progress',
            text: implode("\n", array_map(static fn (int $i): string => 'Transcript sentinel '.$i, range(1, 60))),
            seq: 1,
        );

        $screen->setTranscriptBlocks([$transcript, $this->parallelBlock($this->parallelProgress('running', 0, null), 2)]);
        $tui->requestRender();
        $tui->processRender();
        $output->consumeOutput();

        $screen->setTranscriptBlocks([$transcript, $this->parallelBlock($this->parallelProgress('running', 0, 'read: path="AGENTS.md"'), 2)]);
        $tui->requestRender();
        $tui->processRender();
        $runningDelta = $output->consumeOutput();

        $screen->setTranscriptBlocks([$transcript, $this->parallelBlock($this->parallelProgress('completed', 2, null), 2)]);
        $tui->requestRender();
        $tui->processRender();
        $completedDelta = $output->consumeOutput();

        foreach ([$runningDelta, $completedDelta] as $delta) {
            $this->assertStringNotContainsString("\x1b[2J", $delta);
            $this->assertStringNotContainsString("\x1b[3J", $delta);
            $this->assertGreaterThan(0, substr_count($delta, "\x1b[2K"));
            $this->assertLessThanOrEqual(8, substr_count($delta, "\x1b[2K"), 'A compact parallel card update must stay within its eight-row footprint.');
        }
    }

    /** @param array<string, mixed> $progress */
    private function parallelBlock(array $progress, int $seq): TranscriptBlock
    {
        $snapshot = SubagentProgressSerializerTestSupport::denormalizer()->denormalize(
            $progress,
            SubagentProgressSnapshotInterface::class,
        );
        $this->assertInstanceOf(SubagentProgressSnapshotInterface::class, $snapshot);

        return new TranscriptBlock(
            id: 'tool_result_parallel',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'compact-progress',
            seq: $seq,
            text: '',
            meta: ['tool_name' => 'subagent', 'subagent_progress' => $snapshot],
            streaming: 'completed' !== $progress['status'],
        );
    }

    /** @return array<string, mixed> */
    private function parallelProgress(string $status, int $completedCount, ?string $activeTool): array
    {
        $child = static fn (int $index): array => [
            'index' => $index,
            'agent_name' => 1 === $index ? 'scout' : 'reviewer',
            'status' => $status,
            'artifact_id' => 'agent_'.$index,
            'agent_run_id' => 'run-'.$index,
            'task_summary' => 1 === $index ? 'Read docs' : 'Review patch',
            'model' => 'test/model',
            'reasoning' => 'medium',
            'active_tool' => $activeTool,
            'tool_count' => null === $activeTool ? $completedCount : 1,
        ];

        return [
            'mode' => 'parallel',
            'status' => $status,
            'completed_count' => $completedCount,
            'total_count' => 2,
            'children' => [$child(1), $child(2)],
        ];
    }
}
