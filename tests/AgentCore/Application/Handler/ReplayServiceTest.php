<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Domain\Run\TurnTreeProjector;
use Ineersa\AgentCore\Application\Replay\TurnTreeReplayFilter;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
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

    // ── Branch-aware prompt replay ──────────────────────────────────────────

    public function testBranchReplayExcludesAbandonedBranchMessages(): void
    {
        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService(
            $eventStore,
            $hotPromptStore,
            turnTreeReplayFilter: new TurnTreeReplayFilter(new TurnTreeProjector()),
        );

        $runId = 'run-branch-replay';

        // Turn 1: initial
        $this->appendTo($eventStore, $runId, 'run_started', 1, 0, [
            'messages' => [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => 'Hello']],
            ]],
        ]);
        // Turn 1 assistant
        $this->appendTo($eventStore, $runId, 'assistant_message', 2, 1, [
            'message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
            ],
        ]);

        // Turn 2: follow-up (ABANDONED branch)
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::TurnAdvanced->value, 3, 2, [
            'turn_no' => 2, 'parent_turn_no' => 1, 'step_id' => 's2',
        ]);
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::LeafSet->value, 4, 2, [
            'turn_no' => 2, 'parent_turn_no' => 1, 'reason' => 'continue',
        ]);
        $this->appendTo($eventStore, $runId, 'assistant_message', 5, 2, [
            'message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ABANDONED response']],
            ],
        ]);

        // Rewind to turn 1, branch turn 3 (ACTIVE)
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::LeafSet->value, 6, 1, [
            'turn_no' => 1, 'reason' => 'rewind',
        ]);
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::TurnAdvanced->value, 7, 3, [
            'turn_no' => 3, 'parent_turn_no' => 1, 'step_id' => 's3',
        ]);
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::LeafSet->value, 8, 3, [
            'turn_no' => 3, 'parent_turn_no' => 1, 'reason' => 'continue',
        ]);
        $this->appendTo($eventStore, $runId, 'assistant_message', 9, 3, [
            'message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ACTIVE response']],
            ],
        ]);

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        // Integrity must describe the full canonical stream.
        self::assertSame(9, $rebuiltState->eventCount);
        self::assertSame(9, $rebuiltState->lastSeq);
        self::assertTrue($rebuiltState->isContiguous, 'Full canonical stream is contiguous');

        // Messages must only contain active-branch messages.
        $messageTexts = [];
        foreach ($rebuiltState->messages as $msg) {
            $messageTexts[] = $msg['content'][0]['text'] ?? '';
        }

        self::assertContains('Hello', $messageTexts);
        self::assertContains('Hi!', $messageTexts);
        self::assertContains('ACTIVE response', $messageTexts);
        self::assertNotContains('ABANDONED response', $messageTexts, 'Abandoned branch messages must be excluded');
    }

    // ── Helper ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private function appendTo(RunEventStore $store, string $runId, string $type, int $seq, int $turnNo, array $payload): void
    {
        $store->append(new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
            createdAt: new \DateTimeImmutable(),
        ));
    }
}
