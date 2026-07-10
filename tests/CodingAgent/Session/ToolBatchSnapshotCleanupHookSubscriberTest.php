<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\InMemoryToolBatchStore;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Session\ToolBatchSnapshotCleanupHookSubscriber;
use PHPUnit\Framework\TestCase;

final class ToolBatchSnapshotCleanupHookSubscriberTest extends TestCase
{
    public function testDeletesExactBatchAfterToolBatchCommitted(): void
    {
        $store = new InMemoryToolBatchStore();
        $store->save('run-1', 3, 'step-x', ['finalized' => true]);
        $store->save('run-1', 3, 'step-other', ['finalized' => false]);

        $logger = new TestLogger();
        $subscriber = new ToolBatchSnapshotCleanupHookSubscriber($store, $logger);

        $context = new AfterTurnCommitHookContext(
            runId: 'run-1',
            turnNo: 3,
            status: RunStatus::Running->value,
            events: [
                new AfterTurnCommitEventSummary(10, RunEventTypeEnum::ToolBatchCommitted->value, [
                    'count' => 1,
                    'turn_no' => 3,
                    'step_id' => 'step-x',
                ]),
            ],
            effectsCount: 0,
        );

        $subscriber->handleAfterTurnCommit($context);

        $this->assertNull($store->load('run-1', 3, 'step-x'));
        $this->assertNotNull($store->load('run-1', 3, 'step-other'));
    }

    public function testTerminalAgentEndDeletesAllRemainingSnapshots(): void
    {
        $store = new InMemoryToolBatchStore();
        $store->save('run-1', 1, 's1', ['finalized' => false]);
        $store->save('run-1', 2, 's2', ['finalized' => false]);

        $subscriber = new ToolBatchSnapshotCleanupHookSubscriber($store, new TestLogger());

        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'run-1',
            turnNo: 2,
            status: RunStatus::Completed->value,
            events: [new AfterTurnCommitEventSummary(99, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed'])],
            effectsCount: 0,
        ));

        $this->assertNull($store->load('run-1', 1, 's1'));
        $this->assertNull($store->load('run-1', 2, 's2'));
    }
}
