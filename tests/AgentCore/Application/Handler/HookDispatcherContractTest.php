<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use PHPUnit\Framework\TestCase;

final class HookDispatcherContractTest extends TestCase
{
    public function testAfterTurnCommitSubscribersCanObserveAndMutateContext(): void
    {
        $subscriber = new class implements HookSubscriberInterface {
            public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
            {
                return new AfterTurnCommitHookContext(
                    runId: $context->runId,
                    turnNo: $context->turnNo,
                    status: 'mutated-by-subscriber',
                    events: $context->events,
                    effectsCount: $context->effectsCount + 1,
                    runState: $context->runState,
                );
            }
        };

        $dispatcher = new HookDispatcher([$subscriber]);

        $result = $dispatcher->dispatchAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'run-stage-01',
            turnNo: 2,
            status: 'running',
            events: [new AfterTurnCommitEventSummary(seq: 7, type: 'agent_end')],
            effectsCount: 3,
            runState: new RunState('run-stage-01', RunStatus::Running, turnNo: 2),
        ));

        $this->assertSame('run-stage-01', $result->runId);
        $this->assertSame('mutated-by-subscriber', $result->status);
        $this->assertSame(4, $result->effectsCount);
        $this->assertContainsOnlyInstancesOf(AfterTurnCommitEventSummary::class, $result->events);
    }
}
