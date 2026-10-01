<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

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

        $events = iterator_to_array($store->rangeFor('parent-ev-router', 1, \PHP_INT_MAX), false);
        $this->assertNotEmpty($events);
        $this->assertSame('parent-ev-router', $events[0]->runId);
    }

    public function testAllForReturnsEmptyForUnknownRunId(): void
    {
        $store = self::getContainer()->get(ChildAwareEventStore::class);

        $events = iterator_to_array($store->rangeFor('nonexistent-ev-id', 1, \PHP_INT_MAX), false);
        $this->assertSame([], $events);
    }

    public function testRangeForDelegatesParentEvents(): void
    {
        $store = self::getContainer()->get(ChildAwareEventStore::class);
        $store->append(new RunEvent(
            runId: 'parent-range-router',
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [],
        ));
        $store->append(new RunEvent(
            runId: 'parent-range-router',
            seq: 2,
            turnNo: 1,
            type: RunEventTypeEnum::TurnAdvanced->value,
            payload: [],
        ));

        $events = iterator_to_array($store->rangeFor('parent-range-router', 2, 2));

        $this->assertSame([2], array_map(static fn (RunEvent $event): int => $event->seq, $events));
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

        $results = iterator_to_array($store->rangeFor('parent-ev-many', 1, \PHP_INT_MAX), false);
        $this->assertCount(2, $results);
    }
}
