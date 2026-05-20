<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use PHPUnit\Framework\TestCase;

final class ReplayServiceTest extends TestCase
{
    public function testRebuildUsesCanonicalEventsAndRestoresDeletedHotPromptState(): void
    {
        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService($eventStore, $hotPromptStore);

        $runId = 'run-replay-canonical';
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: [
                'messages' => [[
                    'role' => 'user',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Hello',
                    ]],
                ]],
            ],
            createdAt: new \DateTimeImmutable('2026-04-12T12:00:00+00:00'),
        ));
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 2,
            turnNo: 1,
            type: 'assistant_message',
            payload: [
                'message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Hi!',
                    ]],
                ],
            ],
            createdAt: new \DateTimeImmutable('2026-04-12T12:01:00+00:00'),
        ));

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        self::assertSame('canonical_events', $rebuiltState->source);
        self::assertSame(2, $rebuiltState->lastSeq);
        self::assertCount(2, $rebuiltState->messages);
        self::assertNotNull($hotPromptStore->get($runId));

        $hotPromptStore->delete($runId);
        self::assertNull($hotPromptStore->get($runId));

        $rebuiltAfterDelete = $replayService->rebuildHotPromptState($runId);

        self::assertSame($rebuiltState->messages, $rebuiltAfterDelete->messages);
        self::assertNotNull($hotPromptStore->get($runId));

        $integrity = $replayService->verifyIntegrity($runId);
        self::assertTrue($integrity->isContiguous);
        self::assertSame([], $integrity->missingSequences);
    }

    public function testRebuildReturnsEmptyResultWhenNoEventsExist(): void
    {
        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService($eventStore, $hotPromptStore);

        $runId = 'run-no-events';

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        self::assertSame('canonical_events', $rebuiltState->source);
        self::assertSame(0, $rebuiltState->lastSeq);
        self::assertCount(0, $rebuiltState->messages);
        self::assertTrue($rebuiltState->isContiguous);
    }
}
