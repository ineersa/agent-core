<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\RunOperationalProjectionWriterInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\ActiveRunContext;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactEntryDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathsDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Artifact\RunOwnerSessionResolver;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class ActiveRunContextTest extends IsolatedKernelTestCase
{
    public function testStateForReplaysAndPersistsOnlyOnFirstMiss(): void
    {
        $runId = 'parent-run';
        $rebuilt = new RunState($runId, RunStatus::Running, turnNo: 2, lastSeq: 4);
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $writer = $this->createMock(RunOperationalProjectionWriterInterface::class);
        $rebuilder->expects($this->once())->method('rebuildIfStale')->with(
            $this->callback(static fn (RunState $state): bool => $runId === $state->runId && 0 === $state->lastSeq),
            $runId,
        )->willReturn(RunStateReplayResult::rebuilt($rebuilt, 4, 4, true));
        $writer->expects($this->once())->method('replace')->with($runId, $rebuilt);

        $context = $this->context($rebuilder, $writer);
        $this->assertSame($rebuilt, $context->stateFor($runId));
        $this->assertSame($rebuilt, $context->stateFor($runId));
    }

    public function testStateForNoEventsPersistsAndCachesQueuedState(): void
    {
        $runId = 'empty-run';
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $writer = $this->createMock(RunOperationalProjectionWriterInterface::class);
        $rebuilder->expects($this->once())->method('rebuildIfStale')->willReturn(RunStateReplayResult::noEvents());
        $writer->expects($this->once())->method('replace')->with(
            $runId,
            $this->callback(static fn (RunState $state): bool => RunStatus::Queued === $state->status && 0 === $state->lastSeq),
        );

        $context = $this->context($rebuilder, $writer);
        $this->assertSame(RunStatus::Queued, $context->stateFor($runId)->status);
        $this->assertSame(RunStatus::Queued, $context->stateFor($runId)->status);
    }

    public function testInvalidateReplaysOnlyInvalidatedKeyWhileParentAndChildRemainIndependent(): void
    {
        $parent = new RunState('parent-run', RunStatus::Running, lastSeq: 1);
        $child = new RunState('child-run', RunStatus::Running, lastSeq: 2);
        $parentReloaded = new RunState('parent-run', RunStatus::Completed, lastSeq: 3);
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $writer = $this->createMock(RunOperationalProjectionWriterInterface::class);
        $states = [$parent, $child, $parentReloaded];
        $rebuilder->expects($this->exactly(3))->method('rebuildIfStale')->willReturnCallback(
            static function (RunState $seed, string $runId) use (&$states): RunStateReplayResult {
                $state = array_shift($states);
                self::assertSame($runId, $seed->runId);

                return RunStateReplayResult::rebuilt($state, $state->lastSeq, $state->lastSeq, true);
            },
        );
        $writer->expects($this->exactly(3))->method('replace');

        $context = $this->context($rebuilder, $writer);
        $this->assertSame($parent, $context->stateFor('parent-run'));
        $this->assertSame($child, $context->stateFor('child-run'));
        $context->invalidate('parent-run');
        $this->assertSame($parentReloaded, $context->stateFor('parent-run'));
        $this->assertSame($child, $context->stateFor('child-run'));
    }

    public function testProjectionFailureOnMissLeavesStateUncachedAndRetriesReplay(): void
    {
        $runId = 'retry-run';
        $state = new RunState($runId, RunStatus::Running);
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $writer = $this->createMock(RunOperationalProjectionWriterInterface::class);
        $rebuilder->expects($this->exactly(2))->method('rebuildIfStale')->willReturn(RunStateReplayResult::rebuilt($state, 1, 1, true));
        $writes = 0;
        $writer->expects($this->exactly(2))->method('replace')->willReturnCallback(
            static function () use (&$writes): void {
                ++$writes;
                if (1 === $writes) {
                    throw new \RuntimeException('projection unavailable');
                }
            },
        );
        $context = new ActiveRunContext($rebuilder, $writer, new RunOwnerSessionResolver($this->directory()));
        try {
            $context->stateFor($runId);
            $this->fail('Projection failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('projection unavailable', $e->getMessage());
        }
        $this->assertSame($state, $context->stateFor($runId));
    }

    public function testOwnerResolutionFailureOnMissLeavesStateUncachedAndRetriesReplay(): void
    {
        $directory = $this->directory();
        $directory->register($this->entry('cycle-a', 'cycle-b'));
        $directory->register($this->entry('cycle-b', 'cycle-a'));
        $state = new RunState('cycle-a', RunStatus::Running);
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $writer = $this->createMock(RunOperationalProjectionWriterInterface::class);
        $rebuilder->expects($this->exactly(2))->method('rebuildIfStale')->willReturn(RunStateReplayResult::rebuilt($state, 1, 1, true));
        $writer->expects($this->once())->method('replace')->with('cycle-a', $state);

        $context = new ActiveRunContext($rebuilder, $writer, new RunOwnerSessionResolver($directory));
        try {
            $context->stateFor('cycle-a');
            $this->fail('Owner resolution failure must propagate.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('ownership cycle', $e->getMessage());
        }
        $directory->unregister('cycle-a');
        $directory->unregister('cycle-b');
        $this->assertSame($state, $context->stateFor('cycle-a'));
    }

    public function testRememberWritesBeforeReplacingCachedState(): void
    {
        $runId = 'remember-run';
        $old = new RunState($runId, RunStatus::Running, lastSeq: 1);
        $next = new RunState($runId, RunStatus::Completed, lastSeq: 2);
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $writer = $this->createMock(RunOperationalProjectionWriterInterface::class);
        $rebuilder->expects($this->once())->method('rebuildIfStale')->willReturn(RunStateReplayResult::rebuilt($old, 1, 1, true));
        $context = $this->context($rebuilder, $writer);
        $writer->expects($this->exactly(2))->method('replace')->willReturnCallback(
            static function (string $owner, RunState $state) use (&$context, $old, $next): void {
                if ($next === $state) {
                    self::assertSame($old, $context->stateFor($state->runId));
                }
            },
        );

        $context->stateFor($runId);
        $context->remember($next);
        $this->assertSame($next, $context->stateFor($runId));
    }

    public function testFailedRememberInvalidatesCachedStateAndNextStateForReplays(): void
    {
        $runId = 'failed-remember-run';
        $old = new RunState($runId, RunStatus::Running, lastSeq: 1);
        $next = new RunState($runId, RunStatus::Completed, lastSeq: 2);
        $replayed = new RunState($runId, RunStatus::Failed, lastSeq: 3);
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $writer = $this->createMock(RunOperationalProjectionWriterInterface::class);
        $rebuilder->expects($this->exactly(2))->method('rebuildIfStale')->willReturnOnConsecutiveCalls(
            RunStateReplayResult::rebuilt($old, 1, 1, true),
            RunStateReplayResult::rebuilt($replayed, 3, 3, true),
        );
        $writes = 0;
        $writer->expects($this->exactly(3))->method('replace')->willReturnCallback(
            static function () use (&$writes): void {
                ++$writes;
                if (2 === $writes) {
                    throw new \RuntimeException('projection unavailable');
                }
            },
        );

        $context = $this->context($rebuilder, $writer);
        $context->stateFor($runId);
        try {
            $context->remember($next);
            $this->fail('Remember failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('projection unavailable', $e->getMessage());
        }
        $this->assertSame($replayed, $context->stateFor($runId));
    }

    public function testClearDropsAllCachedStates(): void
    {
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $writer = $this->createMock(RunOperationalProjectionWriterInterface::class);
        $rebuilder->expects($this->exactly(4))->method('rebuildIfStale')->willReturnCallback(
            static fn (RunState $state): RunStateReplayResult => RunStateReplayResult::rebuilt($state, 0, 0, true),
        );
        $writer->expects($this->exactly(4))->method('replace');
        $context = $this->context($rebuilder, $writer);

        $context->stateFor('parent-run');
        $context->stateFor('child-run');
        $context->clear();
        $context->stateFor('parent-run');
        $context->stateFor('child-run');
    }

    private function context(RunStateRebuilderInterface $rebuilder, RunOperationalProjectionWriterInterface $writer): ActiveRunContext
    {
        return new ActiveRunContext($rebuilder, $writer, new RunOwnerSessionResolver($this->directory()));
    }

    private function directory(): AgentChildRunDirectory
    {
        return self::getContainer()->get(AgentChildRunDirectory::class);
    }

    private function entry(string $childRunId, string $parentRunId): AgentArtifactEntryDTO
    {
        return new AgentArtifactEntryDTO(
            'artifact-'.$childRunId,
            $parentRunId,
            $childRunId,
            'agent',
            AgentArtifactKindEnum::Subagent,
            AgentArtifactStatusEnum::Running,
            AgentArtifactPathsDTO::forArtifactId('artifact-'.$childRunId),
            new \DateTimeImmutable(),
        );
    }
}
