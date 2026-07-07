<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\SessionTurnTreeProvider;
use Ineersa\CodingAgent\Session\TurnTree\TurnTreeProjector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionTurnTreeProvider::class)]
final class SessionTurnTreeProviderTest extends TestCase
{
    private string $runId = 'provider-test-run';

    public function testForSessionWithEmptyEventsReturnsEmptyTree(): void
    {
        $provider = $this->createProvider([]);
        $tree = $provider->forSession($this->runId);

        $this->assertSame($this->runId, $tree->runId);
        $this->assertSame([], $tree->nodesByTurnNo);
        $this->assertSame([], $tree->rootTurnNos);
        $this->assertNull($tree->currentLeafTurnNo);
        $this->assertSame([], $tree->activePathTurnNos);
    }

    public function testForSessionMapsLinearStreamCorrectly(): void
    {
        $events = [
            $this->runEvent('run_started', 1, 0, [
                'payload' => ['messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
                ]],
            ]),
            $this->turnAdvanced(2, 1, null),
            $this->runEvent('llm_step_completed', 3, 1, ['text' => 'Response']),
            $this->turnAdvanced(4, 2, 1),
            $this->runEvent('llm_step_completed', 5, 2, ['text' => 'Response 2']),
        ];

        $provider = $this->createProvider($events);
        $tree = $provider->forSession($this->runId);

        $this->assertCount(2, $tree->nodesByTurnNo);
        $this->assertSame([1], $tree->rootTurnNos);
        $this->assertSame(2, $tree->currentLeafTurnNo);
        $this->assertSame([1, 2], $tree->activePathTurnNos);

        $turn1 = $tree->nodesByTurnNo[1];
        $this->assertNull($turn1->parentTurnNo);
        $this->assertSame([2], $turn1->childTurnNos);
        $this->assertFalse($turn1->isCurrentLeaf);
        $this->assertSame(2, $turn1->anchorSeq);
        $this->assertStringContainsString('Hello', $turn1->title);

        $turn2 = $tree->nodesByTurnNo[2];
        $this->assertSame(1, $turn2->parentTurnNo);
        $this->assertSame([], $turn2->childTurnNos);
        $this->assertTrue($turn2->isCurrentLeaf);
        $this->assertSame(4, $turn2->anchorSeq);
    }

    public function testForSessionMapsBranchedStreamCorrectly(): void
    {
        // Linear turn 1, branch to turn 2, rewind to turn 1, branch to turn 3
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvanced(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            $this->runEvent('llm_step_completed', 4, 1, ['text' => 'Answer A']),
            // Turn 2: branch from turn 1
            $this->turnAdvanced(5, 2, 1),
            $this->leafSetEvent(6, 2, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 7, 2, ['text' => 'Answer B']),
            // Rewind to turn 1
            $this->leafSetEvent(8, 1, 2, null, 'rewind'),
            // Turn 3: new branch from turn 1
            $this->turnAdvanced(9, 3, 1),
            $this->leafSetEvent(10, 3, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 11, 3, ['text' => 'Answer C']),
        ];

        $provider = $this->createProvider($events);
        $tree = $provider->forSession($this->runId);

        $this->assertCount(3, $tree->nodesByTurnNo);
        $this->assertSame([1], $tree->rootTurnNos);
        $this->assertSame(3, $tree->currentLeafTurnNo);
        $this->assertSame([1, 3], $tree->activePathTurnNos);

        $turn1 = $tree->nodesByTurnNo[1];
        $this->assertNull($turn1->parentTurnNo);
        $this->assertSame([2, 3], $turn1->childTurnNos);
        $this->assertFalse($turn1->isCurrentLeaf);

        $turn2 = $tree->nodesByTurnNo[2];
        $this->assertSame(1, $turn2->parentTurnNo);
        $this->assertFalse($turn2->isCurrentLeaf);

        $turn3 = $tree->nodesByTurnNo[3];
        $this->assertSame(1, $turn3->parentTurnNo);
        $this->assertTrue($turn3->isCurrentLeaf);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Create a provider with a stub SessionRunEventStore returning the given events.
     *
     * @param list<RunEvent> $events
     */
    private function createProvider(array $events): SessionTurnTreeProvider
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('allFor')->willReturn($events);

        $projector = new TurnTreeProjector();

        return new SessionTurnTreeProvider($store, $projector);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function runEvent(string $type, int $seq, int $turnNo, array $payload = []): RunEvent
    {
        return new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
        );
    }

    private function turnAdvanced(int $seq, int $turnNo, ?int $parentTurnNo): RunEvent
    {
        $payload = ['turn_no' => $turnNo, 'step_id' => 'step-'.$turnNo];
        if (null !== $parentTurnNo) {
            $payload['parent_turn_no'] = $parentTurnNo;
        }

        return new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: $turnNo,
            type: RunEventTypeEnum::TurnAdvanced->value,
            payload: $payload,
        );
    }

    private function leafSetEvent(int $seq, int $turnNo, ?int $previousTurnNo, ?int $parentTurnNo, string $reason): RunEvent
    {
        $payload = ['turn_no' => $turnNo, 'reason' => $reason];
        if (null !== $previousTurnNo) {
            $payload['previous_turn_no'] = $previousTurnNo;
        }
        if (null !== $parentTurnNo) {
            $payload['parent_turn_no'] = $parentTurnNo;
        }

        return new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: $turnNo,
            type: RunEventTypeEnum::LeafSet->value,
            payload: $payload,
        );
    }
}
