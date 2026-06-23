<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Agent\Artifact\EventStoreRouter;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class EventStoreRouterTest extends IsolatedKernelTestCase
{
    public function testAppendHandlesParentEvent(): void
    {
        $router = self::getContainer()->get(EventStoreRouter::class);

        $event = new RunEvent(
            runId: 'parent-ev-router',
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: ['step_id' => 'test-step'],
        );

        // Should not throw.
        $router->append($event);

        $events = $router->allFor('parent-ev-router');
        self::assertNotEmpty($events);
        self::assertSame('parent-ev-router', $events[0]->runId);
    }

    public function testAllForReturnsEmptyForUnknownRunId(): void
    {
        $router = self::getContainer()->get(EventStoreRouter::class);

        $events = $router->allFor('nonexistent-ev-id');
        self::assertSame([], $events);
    }

    public function testAppendManyHandlesMultipleParentEvents(): void
    {
        $router = self::getContainer()->get(EventStoreRouter::class);

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

        $router->appendMany($events);

        $results = $router->allFor('parent-ev-many');
        self::assertCount(2, $results);
    }
}
