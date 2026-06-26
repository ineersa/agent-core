<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Projection;

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
        $this->assertCount(1, $blocks);
        $block = $blocks[0];
        $this->assertSame('tool_result_tc_sub', $block->id);
        $this->assertStringContainsString('subagent scout', $block->text);
        $this->assertStringContainsString('running scout', $block->text);
        $this->assertStringContainsString('2 turns', $block->text);
        $this->assertStringContainsString('Task: Inspect TUI', $block->text);
        $this->assertStringContainsString('Artifacts:', $block->text);
        $this->assertStringContainsString('agent_abc', $block->text);
        $this->assertStringNotContainsString('subagent scout running | turn 1', $block->text);
        $this->assertSame(2, $block->meta['subagent_progress']['turn_no'] ?? null);
    }

    public function testParallelSubagentProgressRendersChildSingleWidgetSections(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_par', 'tool_name' => 'subagent',
        ]);

        $progress = [
            'mode' => 'parallel', 'status' => 'running', 'completed_count' => 1, 'total_count' => 2, 'elapsed_ms' => 42000,
            'children' => [
                [
                    'index' => 1, 'label' => 'Step 1', 'agent_name' => 'reviewer', 'status' => 'completed',
                    'artifact_id' => 'agent_a', 'task_summary' => 'Review code', 'turn_no' => 3,
                    'tool_count' => 5, 'total_tokens' => 12000, 'input_tokens' => 8000, 'output_tokens' => 4000,
                    'artifact_path' => 'artifacts/agents/agent_a', 'model' => 'test/model-a',
                ],
                [
                    'index' => 2, 'label' => 'Step 2', 'agent_name' => 'scout', 'status' => 'running',
                    'artifact_id' => 'agent_b', 'task_summary' => 'Inspect TUI', 'turn_no' => 2, 'elapsed_ms' => 15000,
                    'tool_count' => 12, 'total_tokens' => 49000,
                    'artifact_path' => 'artifacts/agents/agent_b',
                    'recent_tools' => ['read: path="src/Tui/Transcript/SubagentResultRenderer.php"'],
                    'assistant_excerpt' => 'Tracing projection path.',
                ],
            ],
        ];

        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_par', 'tool_name' => 'subagent', 'subagent_progress' => $progress,
        ]);

        $text = $this->projector->blocks()[0]->text;
        $this->assertStringContainsString('parallel subagents running (1/2 completed)', $text);
        $this->assertStringContainsString('#1 subagent reviewer', $text);
        $this->assertStringContainsString('#2 subagent scout', $text);
        $this->assertStringContainsString('running scout | 12 tools | 49k tok', $text);
        $this->assertStringContainsString('Task: Inspect TUI', $text);
        $this->assertStringContainsString('Artifacts: artifacts/agents/agent_b', $text);
        $this->assertStringContainsString('SubagentResultRenderer', $text);
        $this->assertStringContainsString('Tracing projection path.', $text);
        $this->assertStringNotContainsString('completed Step 1: reviewer', $text);
        $this->assertStringNotContainsString('running Step 2: scout', $text);
        $this->assertStringNotContainsString('| artifact agent_b', $text);
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
        $this->assertCount(1, $blocks);
        $text = $blocks[0]->text;
        $this->assertStringContainsString('38 tools', $text);
        $this->assertStringContainsString('49k tok', $text);
        $this->assertStringContainsString('grep', $text);
        $this->assertStringNotContainsString('docs/agents.md', $text);
        $this->assertStringNotContainsString('| turn 2 | artifact', $text);
    }

    public function testSubagentProgressTerminalAfterCompleted(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_done', 'tool_name' => 'subagent',
        ]);

        $running = [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_done', 'task_summary' => 'Inspect TUI', 'turn_no' => 2, 'elapsed_ms' => 5000,
        ];
        $completed = $running;
        $completed['status'] = 'completed';
        $completed['turn_no'] = 3;
        $completed['tool_count'] = 5;
        $completed['artifact_path'] = 'artifacts/agents/agent_done';

        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_done', 'tool_name' => 'subagent', 'subagent_progress' => $running,
        ]);
        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_done', 'tool_name' => 'subagent', 'subagent_progress' => $completed,
        ]);

        $handoff = "Subagent scout completed.\nArtifact: agent_done\n\nDone.";
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_done', 'result' => $handoff,
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertStringContainsString('completed scout', $block->text);
        $this->assertStringNotContainsString('running scout', $block->text);
        $this->assertStringContainsString('agent_done', $block->text);
        $this->assertStringContainsString('Done.', $block->text);
        $this->assertTrue($block->meta['subagent_final'] ?? false);
    }

    public function testSubagentProgressFailedPreservesStructuredWidget(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_fail', 'tool_name' => 'subagent',
        ]);

        $failed = [
            'mode' => 'single', 'status' => 'failed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_fail', 'task_summary' => 'Inspect TUI', 'turn_no' => 2, 'elapsed_ms' => 5000,
            'artifact_path' => 'artifacts/agents/agent_fail',
        ];

        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_fail', 'tool_name' => 'subagent', 'subagent_progress' => $failed,
        ]);
        $this->accept('tool_execution.failed', [
            'tool_call_id' => 'tc_fail', 'result' => 'Subagent tool failed: child denied approval.',
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertStringContainsString('failed scout', $block->text);
        $this->assertStringContainsString('agent_fail', $block->text);
        $this->assertStringContainsString('child denied approval', $block->text);
        $this->assertTrue($block->meta['subagent_final'] ?? false);
        $this->assertSame('subagent', $block->meta['tool_name'] ?? null);
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
