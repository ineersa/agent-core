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
        self::assertStringContainsString('subagent scout', $block->text);
        self::assertStringContainsString('running scout', $block->text);
        self::assertStringContainsString('2 turns', $block->text);
        self::assertStringContainsString('Task: Inspect TUI', $block->text);
        self::assertStringContainsString('Artifacts:', $block->text);
        self::assertStringContainsString('agent_abc', $block->text);
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
        self::assertStringContainsString('subagent parallel', $text);
        self::assertStringContainsString('running 1/2', $text);
        self::assertStringContainsString('completed Step 1: reviewer', $text);
        self::assertStringContainsString('running Step 2: scout', $text);
        self::assertStringContainsString('artifact agent_b', $text);
    }


    public function testRichSubagentProgressCoalescesWithoutDeltaSpam(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_rich', 'tool_name' => 'subagent',
        ]);

        $progress1 = [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_rich', 'task_summary' => 'Inspect docs', 'turn_no' => 2, 'elapsed_ms' => 139000,
            'tool_count' => 5, 'total_tokens' => 49000, 'input_tokens' => 35000, 'output_tokens' => 14000,
            'reasoning_tokens' => 584000, 'cost' => 0.0104, 'model' => 'deepseek/deepseek-v4-flash',
            'artifact_path' => 'artifacts/agents/agent_rich',
            'recent_tools' => ['read: path="docs/agents.md"'],
            'assistant_excerpt' => 'Scanning agent docs.',
        ];
        $progress2 = $progress1;
        $progress2['turn_no'] = 3;
        $progress2['tool_count'] = 38;
        $progress2['recent_tools'] = ['bash: command="grep -n subagent"'];

        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_rich', 'tool_name' => 'subagent', 'subagent_progress' => $progress1,
        ]);
        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_rich', 'tool_name' => 'subagent', 'subagent_progress' => $progress2,
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);
        $text = $blocks[0]->text;
        self::assertStringContainsString('38 tools', $text);
        self::assertStringContainsString('49k tok', $text);
        self::assertStringContainsString('grep', $text);
        self::assertStringNotContainsString('docs/agents.md', $text);
        self::assertStringNotContainsString('| turn 2 | artifact', $text);
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
