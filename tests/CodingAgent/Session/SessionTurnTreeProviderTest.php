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
            $this->turnAdvanced(2, 1),
            $this->runEvent('llm_step_completed', 3, 1, ['text' => 'Response']),
            $this->turnAdvanced(4, 2),
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

    /**
     * Thesis: without history_tail_discarded, abandoned forward turns would remain
     * active after a mutate-behind-tip path; projection must drop turns after after_turn_no.
     */
    public function testForSessionAppliesHistoryTailDiscard(): void
    {
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvanced(2, 1),
            $this->leafSet(3, 1, null, 'continue'),
            $this->runEvent('llm_step_completed', 4, 1, ['text' => 'Answer A']),
            $this->turnAdvanced(5, 2),
            $this->leafSet(6, 2, 1, 'continue'),
            $this->runEvent('llm_step_completed', 7, 2, ['text' => 'Answer B']),
            // Select before turn 2 then discard forward tail (after turn 1).
            $this->leafSet(8, 1, 2, 'history_select'),
            $this->historyTailDiscarded(9, 1),
            $this->turnAdvanced(10, 3),
            $this->leafSet(11, 3, 1, 'continue'),
            $this->runEvent('llm_step_completed', 12, 3, ['text' => 'Answer C']),
        ];

        $provider = $this->createProvider($events);
        $tree = $provider->forSession($this->runId);

        $this->assertCount(2, $tree->nodesByTurnNo);
        $this->assertSame([1, 3], $tree->activePathTurnNos);
        $this->assertSame(3, $tree->currentLeafTurnNo);
        $this->assertArrayNotHasKey(2, $tree->nodesByTurnNo);

        $turn1 = $tree->nodesByTurnNo[1];
        $this->assertSame([3], $turn1->childTurnNos);
        $this->assertSame(1, $tree->nodesByTurnNo[3]->parentTurnNo);
    }

    /**
     * @param list<RunEvent> $events
     */
    private function createProvider(array $events): SessionTurnTreeProvider
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('allFor')->willReturn($events);

        return new SessionTurnTreeProvider($store, new TurnTreeProjector());
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

    private function turnAdvanced(int $seq, int $turnNo): RunEvent
    {
        return new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: $turnNo,
            type: RunEventTypeEnum::TurnAdvanced->value,
            payload: ['turn_no' => $turnNo, 'step_id' => 'step-'.$turnNo],
        );
    }

    private function leafSet(int $seq, int $turnNo, ?int $previousTurnNo, string $reason): RunEvent
    {
        $payload = ['turn_no' => $turnNo, 'reason' => $reason];
        if (null !== $previousTurnNo) {
            $payload['previous_turn_no'] = $previousTurnNo;
        }

        return new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: $turnNo,
            type: RunEventTypeEnum::LeafSet->value,
            payload: $payload,
        );
    }

    private function historyTailDiscarded(int $seq, int $afterTurnNo): RunEvent
    {
        return new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: $afterTurnNo,
            type: RunEventTypeEnum::HistoryTailDiscarded->value,
            payload: ['after_turn_no' => $afterTurnNo, 'reason' => 'mutate_behind_tip'],
        );
    }
}
