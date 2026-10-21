<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred\Progress;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress\DeferredSubagentBatchProgressSnapshotFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Runtime\DTO\SubagentProgressSnapshotDTO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Thesis: child elapsed_ms freezes from startedAt/terminalCompletedAt; single
 * terminal snapshots do not keep advancing from batch start on later rebuilds.
 */
final class DeferredSubagentBatchProgressSnapshotFactoryTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('terminalStatuses')]
    public function testSingleTerminalElapsedFreezesFromChildTimestamps(RunStatus $status): void
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
                projection: $this->projection($status, toolCount: 38, totalTokens: 49000),
            ),
            startedAt: $started,
        );

        $first = $factory->buildNormalPayload($batch);
        $this->assertInstanceOf(SubagentProgressSnapshotDTO::class, $first);
        $this->assertSame(139000, $first->elapsedMs);

        $clock->modify('+10 minutes');
        $second = $factory->buildNormalPayload($batch);
        $this->assertInstanceOf(SubagentProgressSnapshotDTO::class, $second);
        $this->assertSame(139000, $second->elapsedMs);
    }

    public static function terminalStatuses(): iterable
    {
        yield [RunStatus::Completed];
        yield [RunStatus::Failed];
        yield [RunStatus::Cancelled];
    }

    private function factory(MockClock $clock): DeferredSubagentBatchProgressSnapshotFactory
    {
        return new DeferredSubagentBatchProgressSnapshotFactory(
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
