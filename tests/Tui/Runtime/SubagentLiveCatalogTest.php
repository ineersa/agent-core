<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Runtime\SubagentLiveCatalog;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use PHPUnit\Framework\TestCase;

/** @covers \Ineersa\Tui\Runtime\SubagentLiveCatalog */
final class SubagentLiveCatalogTest extends TestCase
{
    public function testIngestsSingleModeProgressWithAgentRunId(): void
    {
        $catalog = new SubagentLiveCatalog();
        $catalog->ingestRuntimeEvent($this->progressEvent('parent-1', [
            'mode' => 'single',
            'status' => 'running',
            'agent_name' => 'scout',
            'artifact_id' => 'agent_a',
            'agent_run_id' => 'child-run-1',
            'task_summary' => 'Find files',
        ]));

        $all = $catalog->all();
        $this->assertCount(1, $all);
        $this->assertSame('child-run-1', $all[0]->agentRunId);
        $this->assertSame(SubagentLiveStatusEnum::Running, $all[0]->status);
    }

    public function testPreservesAgentRunIdWhenLaterProgressOmitsIt(): void
    {
        $catalog = new SubagentLiveCatalog();
        $catalog->ingestRuntimeEvent($this->progressEvent('parent-1', [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task',
        ]));
        $catalog->ingestRuntimeEvent($this->progressEvent('parent-1', [
            'mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'task_summary' => 'Task done',
        ]));

        $child = $catalog->findByArtifactId('agent_a');
        $this->assertNotNull($child);
        $this->assertSame('child-run-1', $child->agentRunId);
        $this->assertSame(SubagentLiveStatusEnum::Completed, $child->status);
    }

    public function testIgnoresRowWithoutResolvableAgentRunId(): void
    {
        $catalog = new SubagentLiveCatalog();
        $catalog->ingestRuntimeEvent($this->progressEvent('parent-1', [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'task_summary' => 'No id',
        ]));
        $this->assertSame([], $catalog->all());
    }

    public function testIngestsParallelChildrenRows(): void
    {
        $catalog = new SubagentLiveCatalog();
        $catalog->ingestRuntimeEvent($this->progressEvent('parent-1', [
            'mode' => 'parallel', 'status' => 'running',
            'children' => [
                ['agent_name' => 'scout', 'artifact_id' => 'a1', 'agent_run_id' => 'run-1', 'status' => 'running', 'task_summary' => 'One'],
                ['agent_name' => 'worker', 'artifact_id' => 'a2', 'agent_run_id' => 'run-2', 'status' => 'completed', 'task_summary' => 'Two'],
            ],
        ]));
        $this->assertCount(2, $catalog->all());
        $this->assertSame(SubagentLiveStatusEnum::Completed, $catalog->findByArtifactId('a2')?->status);
    }

    /** @param array<string, mixed> $progress */
    private function progressEvent(string $runId, array $progress): RuntimeEvent
    {
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            runId: $runId,
            seq: 1,
            payload: ['tool_call_id' => 'tc1', 'tool_name' => 'subagent', 'delta' => '', 'subagent_progress' => $progress],
        );
    }
}
