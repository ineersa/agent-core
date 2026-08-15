<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
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
        ));

        $this->assertSame('run-stage-01', $result->runId);
        $this->assertSame('mutated-by-subscriber', $result->status);
        $this->assertSame(4, $result->effectsCount);
        $this->assertContainsOnlyInstancesOf(AfterTurnCommitEventSummary::class, $result->events);
    }

    public function testSubscribersRunInRegistrationOrder(): void
    {
        $order = [];

        $first = new class($order) implements HookSubscriberInterface {
            public function __construct(private array &$order)
            {
            }

            public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
            {
                $this->order[] = 'first';

                return $context;
            }
        };

        $second = new class($order) implements HookSubscriberInterface {
            public function __construct(private array &$order)
            {
            }

            public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
            {
                $this->order[] = 'second';

                return $context;
            }
        };

        $dispatcher = new HookDispatcher([$first, $second]);

        $dispatcher->dispatchAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'run-stage-03',
            turnNo: 1,
            status: 'running',
            events: [],
            effectsCount: 0,
        ));

        $this->assertSame(['first', 'second'], $order);
    }
}
