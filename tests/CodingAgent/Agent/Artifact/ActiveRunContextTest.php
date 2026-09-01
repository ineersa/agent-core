<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\ActiveRunContext;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ActiveRunContextTest extends IsolatedKernelTestCase
{
    private RunOperationalProjectionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get('test.run_operational_projection_repository');
    }

    public function testCacheMissReplaysOnceAndPersistsTheResult(): void
    {
        $state = new RunState('run-1', RunStatus::Running, lastSeq: 4);
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->expects($this->once())->method('rebuildIfStale')->willReturn(RunStateReplayResult::rebuilt($state));
        $context = new ActiveRunContext($rebuilder, $this->repository);

        $this->assertSame($state, $context->stateFor('run-1'));
        $this->assertSame($state, $context->stateFor('run-1'));
        $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('run-1')?->status);
    }

    public function testRememberPersistsBeforeReplacingCachedState(): void
    {
        $old = new RunState('run-1', RunStatus::Running, lastSeq: 1);
        $next = new RunState('run-1', RunStatus::Completed, lastSeq: 2);
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->expects($this->once())->method('rebuildIfStale')->willReturn(RunStateReplayResult::rebuilt($old));
        $context = new ActiveRunContext($rebuilder, $this->repository);

        $context->stateFor('run-1');
        $context->remember($next);
        $this->assertSame($next, $context->stateFor('run-1'));
        $this->assertSame(RunStatus::Completed, $this->repository->findOperationalStatus('run-1')?->status);
    }

    public function testPersistenceFailureInvalidatesCachedStateBeforeTheNextReplay(): void
    {
        $old = new RunState('run-1', RunStatus::Running, lastSeq: 1);
        $invalid = new RunState('run-1', RunStatus::Completed, activeStepId: str_repeat('x', 256));
        $replayed = new RunState('run-1', RunStatus::Failed, lastSeq: 3);
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->expects($this->exactly(2))->method('rebuildIfStale')->willReturnOnConsecutiveCalls(
            RunStateReplayResult::rebuilt($old),
            RunStateReplayResult::rebuilt($replayed),
        );
        $context = new ActiveRunContext($rebuilder, $this->repository);

        $context->stateFor('run-1');
        try {
            $context->remember($invalid);
            $this->fail('Invalid projection must fail.');
        } catch (ValidationFailedException) {
        }

        $this->assertSame($replayed, $context->stateFor('run-1'));
    }
}
