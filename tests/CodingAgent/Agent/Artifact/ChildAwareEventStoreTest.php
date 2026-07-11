<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareEventStore;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
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
        $this->assertNotEmpty($events);
        $this->assertSame('parent-ev-router', $events[0]->runId);
    }

    public function testAllForReturnsEmptyForUnknownRunId(): void
    {
        $store = self::getContainer()->get(ChildAwareEventStore::class);

        $events = $store->allFor('nonexistent-ev-id');
        $this->assertSame([], $events);
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
        $this->assertCount(2, $results);
    }
    public function testAppendWritesNestedChildEventsUnderArtifactPath(): void
    {
        /** @var HatfieldSessionStore $hatfield */
        $hatfield = self::getContainer()->get(HatfieldSessionStore::class);
        /** @var AgentArtifactRegistry $registry */
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        /** @var AgentArtifactPathResolver $pathResolver */
        $pathResolver = self::getContainer()->get(AgentArtifactPathResolver::class);
        $store = self::getContainer()->get(ChildAwareEventStore::class);

        $mainParentId = $hatfield->createSession('Nested child event store main');
        $forkRunId = 'nested-ev-fork-'.bin2hex(random_bytes(4));
        $forkArtifactId = 'agent_fork_ev_'.bin2hex(random_bytes(4));
        $registry->create($mainParentId, $forkArtifactId, $forkRunId, 'fork', AgentArtifactKindEnum::Fork);

        $scoutRunId = 'nested-ev-scout-'.bin2hex(random_bytes(4));
        $scoutArtifactId = 'agent_scout_ev_'.bin2hex(random_bytes(4));
        $registry->create($forkRunId, $scoutArtifactId, $scoutRunId, 'scout', AgentArtifactKindEnum::Subagent);

        $event = new RunEvent(
            runId: $scoutRunId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: ['step_id' => 'nested-step'],
        );

        $store->append($event);

        $eventsPath = $pathResolver->eventsPath($forkRunId, $scoutArtifactId);
        $this->assertFileExists($eventsPath, 'Nested child events must persist under fork-scoped artifact directory');

        $events = $store->allFor($scoutRunId);
        $this->assertCount(1, $events);
        $this->assertSame($scoutRunId, $events[0]->runId);
    }

}
