<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\DTO\BackgroundChildStatusDTO;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Runtime\SubagentLiveCatalog;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use PHPUnit\Framework\TestCase;

/** @covers \Ineersa\Tui\Runtime\SubagentLiveCatalog */
final class SubagentLiveCatalogTest extends TestCase
{
    public function testOperationalRefreshUpdatesMetadataWithoutParentProgressAndIdenticalSnapshotsAreNoOps(): void
    {
        $catalog = new SubagentLiveCatalog();
        $child = new BackgroundChildStatusDTO('batch', 'run', 'artifact', 'fork', 'task', 'provider/model', 'low', 'running', true);
        $catalog->synchronizeOperationalStatus([$child]);
        $first = $catalog->findByArtifactId('artifact');
        $this->assertNotNull($first);
        $this->assertSame('provider/model', $first->model);
        $catalog->synchronizeOperationalStatus([$child]);
        $this->assertSame($first, $catalog->findByArtifactId('artifact'));
        $catalog->synchronizeOperationalStatus([new BackgroundChildStatusDTO('batch', 'run', 'artifact', 'fork', 'task', 'provider/new-model', 'high', 'running', true, 23000, 272000)]);
        $current = $catalog->findByArtifactId('artifact');
        $this->assertNotNull($current);
        $this->assertSame('provider/new-model', $current->model);
        $this->assertSame('high', $current->reasoning);
        $this->assertSame(23000, $current->latestInputTokens);
        $this->assertSame(272000, $current->contextWindow);
        $catalog->synchronizeOperationalStatus([new BackgroundChildStatusDTO('batch', 'run', 'artifact', 'fork', 'task', 'provider/new-model', 'high', 'waiting_human', true, 23000, 272000)]);
        $this->assertTrue($catalog->findByArtifactId('artifact')->needsAttention());
        $catalog->synchronizeOperationalStatus([new BackgroundChildStatusDTO('batch', 'run', 'artifact', 'fork', 'task', 'provider/new-model', 'high', 'completed', true, 23000, 272000)]);
        $this->assertFalse($catalog->findByArtifactId('artifact')->needsAttention());
        $this->assertTrue($catalog->findByArtifactId('artifact')->isTerminal());
    }

    public function testIngestsSingleModeProgressWithAgentRunId(): void
    {
        $catalog = new SubagentLiveCatalog();
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', [
            'mode' => 'single',
            'status' => 'running',
            'agent_name' => 'scout',
            'artifact_id' => 'agent_a',
            'agent_run_id' => 'child-run-1',
            'task_summary' => 'Find files',
            'model' => 'deepseek/deepseek-v4-flash',
            'reasoning' => 'medium',
        ]));

        $all = $catalog->all();
        $this->assertCount(1, $all);
        $this->assertSame('child-run-1', $all[0]->agentRunId);
        $this->assertSame(SubagentLiveStatusEnum::Running, $all[0]->status);
    }

    public function testPreservesAgentRunIdWhenLaterProgressOmitsIt(): void
    {
        $catalog = new SubagentLiveCatalog();
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task',
            'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'medium',
        ]));
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', [
            'mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task done',
            'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'medium',
        ]));

        $child = $catalog->findByArtifactId('agent_a');
        $this->assertNotNull($child);
        $this->assertSame('child-run-1', $child->agentRunId);
        $this->assertSame(SubagentLiveStatusEnum::Completed, $child->status);
    }

    public function testMalformedMissingAgentRunIdFailsVisibly(): void
    {
        $catalog = new SubagentLiveCatalog();
        $this->expectException(\Throwable::class);
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'task_summary' => 'No id',
            'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'medium',
        ]));
    }

    public function testIngestsIndependentChildSnapshots(): void
    {
        $catalog = new SubagentLiveCatalog();
        foreach ([
            ['agent_name' => 'scout', 'artifact_id' => 'a1', 'agent_run_id' => 'run-1', 'status' => 'running', 'task_summary' => 'One', 'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'medium'],
            ['agent_name' => 'worker', 'artifact_id' => 'a2', 'agent_run_id' => 'run-2', 'status' => 'completed', 'task_summary' => 'Two', 'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'high'],
        ] as $progress) {
            SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', $progress));
        }
        $this->assertCount(2, $catalog->all());
        $this->assertSame(SubagentLiveStatusEnum::Completed, $catalog->findByArtifactId('a2')?->status);
    }

    public function testResumeProgressWithChangedTaskReopensCompletedCatalogEntry(): void
    {
        $catalog = new SubagentLiveCatalog();
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', [
            'mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task A',
            'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'medium',
        ]));
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task B',
            'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'medium',
        ]));

        $child = $catalog->findByArtifactId('agent_a');
        $this->assertNotNull($child);
        $this->assertSame(SubagentLiveStatusEnum::Running, $child->status);
        $this->assertSame('Task B', $child->taskSummary);
    }

    public function testStaleRunningProgressWithSameTaskDoesNotReopenCompletedCatalogEntry(): void
    {
        $catalog = new SubagentLiveCatalog();
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', [
            'mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task A',
            'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'medium',
        ]));
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task A',
            'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'medium',
        ]));

        $child = $catalog->findByArtifactId('agent_a');
        $this->assertNotNull($child);
        $this->assertSame(SubagentLiveStatusEnum::Completed, $child->status);
    }

    public function testWaitingHumanChildrenSortBeforeRunning(): void
    {
        $catalog = new SubagentLiveCatalog();
        foreach ([
            ['agent_name' => 'scout', 'artifact_id' => 'a1', 'agent_run_id' => 'run-1', 'status' => 'running', 'task_summary' => 'One', 'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'medium'],
            ['agent_name' => 'worker', 'artifact_id' => 'a2', 'agent_run_id' => 'run-2', 'status' => 'waiting_human', 'task_summary' => 'Two', 'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'high'],
        ] as $progress) {
            SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', $progress));
        }

        $all = $catalog->all();
        $this->assertSame('a2', $all[0]->artifactId);
        $this->assertTrue($all[0]->needsAttention());
    }

    public function testDismissedArtifactStaysHiddenAfterStaleProgress(): void
    {
        $catalog = new SubagentLiveCatalog();
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Task',
            'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'medium',
        ]));
        $removed = $catalog->dismissArtifactId('agent_a');
        $this->assertNotNull($removed);
        $this->assertTrue($catalog->isDismissed('agent_a'));
        $this->assertNull($catalog->findByArtifactId('agent_a'));

        SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', [
            'mode' => 'single', 'status' => 'running', 'agent_name' => 'scout',
            'artifact_id' => 'agent_a', 'agent_run_id' => 'child-run-1', 'task_summary' => 'Stale',
            'model' => 'deepseek/deepseek-v4-flash', 'reasoning' => 'medium',
        ]));
        $this->assertNull($catalog->findByArtifactId('agent_a'));
    }

    public function testCatalogChildExposesModelAndLatestInputTokensFromProgress(): void
    {
        $catalog = new SubagentLiveCatalog();
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', [
            'mode' => 'single',
            'status' => 'completed',
            'agent_name' => 'scout',
            'artifact_id' => 'agent_ctx',
            'agent_run_id' => 'child-run-ctx',
            'task_summary' => 'Context stats',
            'model' => \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::MODEL,
            'reasoning' => 'high',
            'latest_input_tokens' => \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::LATEST_INPUT_TOKENS,
            'context_window' => \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::CONTEXT_WINDOW,
        ]));

        $child = $catalog->findByArtifactId('agent_ctx');
        $this->assertNotNull($child);
        $this->assertSame(\Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::MODEL, $this->childContextString($child, 'model'));
        $this->assertSame('high', $this->childContextString($child, 'reasoning'));
        $this->assertSame(\Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::LATEST_INPUT_TOKENS, $this->childContextInt($child, 'latestInputTokens'));
        $this->assertSame(\Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::CONTEXT_WINDOW, $this->childContextInt($child, 'contextWindow'));
    }

    public function testCatalogPreservesModelAndLatestInputTokensAcrossStatusUpdates(): void
    {
        $catalog = new SubagentLiveCatalog();
        $base = [
            'mode' => 'single',
            'agent_name' => 'scout',
            'artifact_id' => 'agent_ctx',
            'agent_run_id' => 'child-run-ctx',
            'task_summary' => 'Context stats',
            'model' => \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::MODEL,
            'reasoning' => 'high',
            'latest_input_tokens' => \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::LATEST_INPUT_TOKENS,
            'context_window' => \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::CONTEXT_WINDOW,
        ];
        SubagentProgressSerializerTestSupport::ingestCatalogEvent($catalog, $this->progressEvent('parent-1', $base + ['status' => 'running']));
        $catalog->applyChildStatus('agent_ctx', SubagentLiveStatusEnum::Cancelled);

        $child = $catalog->findByArtifactId('agent_ctx');
        $this->assertNotNull($child);
        $this->assertSame(SubagentLiveStatusEnum::Cancelled, $child->status);
        $this->assertSame(\Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::MODEL, $this->childContextString($child, 'model'));
        $this->assertSame('high', $this->childContextString($child, 'reasoning'));
        $this->assertSame(\Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::LATEST_INPUT_TOKENS, $this->childContextInt($child, 'latestInputTokens'));
        $this->assertSame(\Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::CONTEXT_WINDOW, $this->childContextInt($child, 'contextWindow'));
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

    private function childContextString(\Ineersa\Tui\Runtime\SubagentLiveChildDTO $child, string $property): ?string
    {
        if (!property_exists($child, $property)) {
            $this->fail('SubagentLiveChildDTO must expose '.$property.' for child context statistics');
        }

        $value = $child->$property;

        return \is_string($value) ? $value : null;
    }

    private function childContextInt(\Ineersa\Tui\Runtime\SubagentLiveChildDTO $child, string $property): int
    {
        if (!property_exists($child, $property)) {
            $this->fail('SubagentLiveChildDTO must expose '.$property.' for child context statistics');
        }

        return (int) $child->$property;
    }
}
