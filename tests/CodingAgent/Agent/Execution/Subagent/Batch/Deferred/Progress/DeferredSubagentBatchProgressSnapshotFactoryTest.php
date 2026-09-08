<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred\Progress;

use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DeferredSubagentBatchChildOutcomeFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress\DeferredSubagentBatchProgressSnapshotFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressParallelSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Thesis: child elapsed_ms freezes from startedAt/terminalCompletedAt; single
 * terminal snapshots do not keep advancing from batch start on later rebuilds.
 */
final class DeferredSubagentBatchProgressSnapshotFactoryTest extends TestCase
{
    public function testSingleTerminalElapsedFreezesFromChildTimestamps(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01T00:05:00Z'));
        $factory = $this->factory($clock);
        $started = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $terminal = new \DateTimeImmutable('2026-01-01T00:02:19Z');
        $batch = $this->singleBatch(
            child: $this->child(
                index: 1,
                runId: 'child-1',
                artifactId: 'agent_1',
                startedAt: $started,
                terminalCompletedAt: $terminal,
                projection: $this->projection(RunStatus::Completed, toolCount: 38, totalTokens: 49000),
            ),
            startedAt: $started,
        );

        $first = $factory->buildNormalPayload($batch);
        $this->assertInstanceOf(SubagentProgressSingleSnapshotDTO::class, $first);
        $this->assertSame(139000, $first->elapsedMs);

        $clock->modify('+10 minutes');
        $second = $factory->buildNormalPayload($batch);
        $this->assertInstanceOf(SubagentProgressSingleSnapshotDTO::class, $second);
        $this->assertSame(139000, $second->elapsedMs);
    }

    public function testParallelChildrenFreezeIndependentlyWhileBatchStillRunning(): void
    {
        $clock = new MockClock(new \DateTimeImmutable('2026-01-01T00:05:00Z'));
        $factory = $this->factory($clock);
        $batchStarted = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $doneStart = new \DateTimeImmutable('2026-01-01T00:00:10Z');
        $doneEnd = new \DateTimeImmutable('2026-01-01T00:00:55Z');
        $runningStart = new \DateTimeImmutable('2026-01-01T00:00:20Z');

        $batch = new DeferredSubagentBatchProjectionDTO(
            lifecycleId: 'batch-1',
            parentRunId: 'parent-1',
            parentTurnNo: 1,
            parentToolCallId: 'tc-1',
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 2,
            launchStatus: DeferredSubagentBatchLaunchStatusEnum::Launched,
            aggregateProgressRevision: 1,
            deliveredProgressRevision: 0,
            terminalCompletionEnqueuedAt: null,
            startedAt: $batchStarted,
            deadlineAt: null,
            createdAt: $batchStarted,
            projectionVersion: 1,
            children: [
                $this->child(
                    index: 1,
                    runId: 'child-done',
                    artifactId: 'agent_done',
                    agentName: 'reviewer',
                    startedAt: $doneStart,
                    terminalCompletedAt: $doneEnd,
                    projection: $this->projection(RunStatus::Completed, toolCount: 5, totalTokens: 12000),
                ),
                $this->child(
                    index: 2,
                    runId: 'child-run',
                    artifactId: 'agent_run',
                    agentName: 'scout',
                    startedAt: $runningStart,
                    terminalCompletedAt: null,
                    projection: $this->projection(RunStatus::Running, toolCount: 3, totalTokens: 9000),
                ),
            ],
        );

        $snapshot = $factory->buildNormalPayload($batch);
        $this->assertInstanceOf(SubagentProgressParallelSnapshotDTO::class, $snapshot);
        $this->assertSame('running', $snapshot->status);
        $this->assertSame(300000, $snapshot->elapsedMs);
        $this->assertSame(45000, $snapshot->children[0]->elapsedMs);
        $this->assertSame(280000, $snapshot->children[1]->elapsedMs);

        $clock->modify('+30 seconds');
        $later = $factory->buildNormalPayload($batch);
        $this->assertInstanceOf(SubagentProgressParallelSnapshotDTO::class, $later);
        $this->assertSame(45000, $later->children[0]->elapsedMs);
        $this->assertSame(310000, $later->children[1]->elapsedMs);
        $this->assertSame(330000, $later->elapsedMs);
    }

    private function factory(MockClock $clock): DeferredSubagentBatchProgressSnapshotFactory
    {
        return new DeferredSubagentBatchProgressSnapshotFactory(
            new DeferredSubagentBatchChildOutcomeFactory(
                $this->createStub(RunStateRebuilderInterface::class),
                new TestLogger(),
            ),
            new SubagentChildProgressSummaryBuilder(),
            new SubagentProgressSnapshotBuilder(),
            $clock,
        );
    }

    private function singleBatch(
        DeferredSubagentChildProjectionDTO $child,
        \DateTimeImmutable $startedAt,
    ): DeferredSubagentBatchProjectionDTO {
        return new DeferredSubagentBatchProjectionDTO(
            lifecycleId: 'batch-single',
            parentRunId: 'parent-1',
            parentTurnNo: 1,
            parentToolCallId: 'tc-1',
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            launchStatus: DeferredSubagentBatchLaunchStatusEnum::Launched,
            aggregateProgressRevision: 1,
            deliveredProgressRevision: 0,
            terminalCompletionEnqueuedAt: null,
            startedAt: $startedAt,
            deadlineAt: null,
            createdAt: $startedAt,
            projectionVersion: 1,
            children: [$child],
        );
    }

    private function child(
        int $index,
        string $runId,
        string $artifactId,
        ?\DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $terminalCompletedAt,
        ?DeferredChildRunLifecycleProjectionDTO $projection,
        string $agentName = 'scout',
    ): DeferredSubagentChildProjectionDTO {
        return new DeferredSubagentChildProjectionDTO(
            batchLifecycleId: 'batch-1',
            batchIndex: $index,
            childRunId: $runId,
            artifactId: $artifactId,
            agentName: $agentName,
            task: 'task '.$agentName,
            launchModel: 'test/model',
            launchReasoning: 'medium',
            launchStatus: DeferredSubagentChildLaunchStatusEnum::Launched,
            childEventCursor: 1,
            childLifecycleProjection: $projection,
            startedAt: $startedAt,
            terminalCompletedAt: $terminalCompletedAt,
            terminalStatus: null !== $terminalCompletedAt ? RunStatus::Completed->value : null,
            projectionVersion: 1,
        );
    }

    private function projection(
        RunStatus $status,
        int $toolCount = 0,
        int $totalTokens = 0,
    ): DeferredChildRunLifecycleProjectionDTO {
        return new DeferredChildRunLifecycleProjectionDTO(
            childStatus: $status,
            childTurnNo: 1,
            lastCommittedSeq: 1,
            model: 'test/model',
            reasoning: 'medium',
            toolCount: $toolCount,
            totalTokens: $totalTokens,
        );
    }
}
