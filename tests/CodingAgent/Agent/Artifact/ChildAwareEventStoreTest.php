<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareEventStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class ChildAwareEventStoreTest extends IsolatedKernelTestCase
{
    public function testAppendHandlesParentEvent(): void
    {
        $store = self::getContainer()->get(ChildAwareEventStore::class);

        $event = new RunEvent(
            runId: 'parent-ev-router',
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: ['step_id' => 'test-step'],
        );

        // Should not throw.
        $store->append($event);

        $events = $store->allFor('parent-ev-router');
        self::assertNotEmpty($events);
        self::assertSame('parent-ev-router', $events[0]->runId);
    }

    public function testAllForReturnsEmptyForUnknownRunId(): void
    {
        $store = self::getContainer()->get(ChildAwareEventStore::class);

        $events = $store->allFor('nonexistent-ev-id');
        self::assertSame([], $events);
    }

    public function testAppendManyHandlesMultipleParentEvents(): void
    {
        $store = self::getContainer()->get(ChildAwareEventStore::class);

        $events = [
            new RunEvent(
                runId: 'parent-ev-many',
                seq: 1,
                turnNo: 0,
                type: RunEventTypeEnum::RunStarted->value,
                payload: [],
            ),
            new RunEvent(
                runId: 'parent-ev-many',
                seq: 2,
                turnNo: 1,
                type: RunEventTypeEnum::TurnAdvanced->value,
                payload: [],
            ),
        ];

        $store->appendMany($events);

        $results = $store->allFor('parent-ev-many');
        self::assertCount(2, $results);
    }
}
