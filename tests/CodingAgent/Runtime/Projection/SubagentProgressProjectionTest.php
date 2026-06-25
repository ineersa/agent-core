<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SubagentProgressProjectionTest extends TestCase
{
    private TranscriptProjector $projector;
    private int $seq = 0;

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher();
        $state = new \Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState();
        $dispatcher->addSubscriber(new ToolProjectionSubscriber());
        $this->projector = new TranscriptProjector($dispatcher, $state);
        $this->seq = 0;
    }

    public function testSubagentProgressCoalescesIntoSingleBlock(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_sub', 'tool_name' => 'subagent',
        ]);

        $progress1 = [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_abc', 'task_summary' => 'Inspect TUI', 'turn_no' => 1, 'elapsed_ms' => 1000,
        ];
        $progress2 = $progress1;
        $progress2['turn_no'] = 2;
        $progress2['elapsed_ms'] = 2500;

        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_sub', 'tool_name' => 'subagent', 'subagent_progress' => $progress1,
        ]);
        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_sub', 'tool_name' => 'subagent', 'subagent_progress' => $progress2,
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);
        $block = $blocks[0];
        self::assertSame('tool_result_tc_sub', $block->id);
        self::assertStringContainsString('subagent scout running', $block->text);
        self::assertStringContainsString('turn 2', $block->text);
        self::assertStringContainsString('Task: Inspect TUI', $block->text);
        self::assertStringContainsString('Artifact: agent_abc', $block->text);
        self::assertStringNotContainsString('subagent scout running | turn 1', $block->text);
        self::assertSame(2, $block->meta['subagent_progress']['turn_no'] ?? null);
    }

    public function testParallelSubagentProgressRendersAggregateRows(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_par', 'tool_name' => 'subagent',
        ]);

        $progress = [
            'mode' => 'parallel', 'status' => 'running', 'completed_count' => 1, 'total_count' => 2, 'elapsed_ms' => 42000,
            'children' => [
                ['index' => 1, 'label' => 'Step 1', 'agent_name' => 'reviewer', 'status' => 'completed', 'artifact_id' => 'agent_a', 'task_summary' => 'Review', 'turn_no' => 3],
                ['index' => 2, 'label' => 'Step 2', 'agent_name' => 'scout', 'status' => 'running', 'artifact_id' => 'agent_b', 'task_summary' => 'Inspect TUI', 'turn_no' => 2],
            ],
        ];

        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_par', 'tool_name' => 'subagent', 'subagent_progress' => $progress,
        ]);

        $text = $this->projector->blocks()[0]->text;
        self::assertStringContainsString('subagent parallel running 1/2', $text);
        self::assertStringContainsString('completed Step 1: reviewer', $text);
        self::assertStringContainsString('running Step 2: scout', $text);
        self::assertStringContainsString('artifact agent_b', $text);
    }

    /** @param array<string, mixed> $payload */
    private function accept(string $type, array $payload): void
    {
        $this->projector->accept([
            'type' => $type,
            'runId' => 'run_subagent',
            'seq' => $this->seq++,
            'payload' => $payload,
            'v' => 1,
        ]);
    }
}
