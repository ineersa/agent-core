<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\TurnTree;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\TurnTree\TurnTreeProjector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TurnTreeProjector::class)]
final class TurnTreeProjectorTest extends TestCase
{
    private TurnTreeProjector $projector;
    private string $runId = 'run-tree-test';

    protected function setUp(): void
    {
        $this->projector = new TurnTreeProjector();
    }

    public function testEmptyStreamReturnsEmptyTree(): void
    {
        $tree = $this->projector->build($this->runId, []);

        $this->assertSame($this->runId, $tree->runId);
        $this->assertSame([], $tree->nodesByTurnNo);
        $this->assertSame([], $tree->rootTurnNos);
        $this->assertNull($tree->currentLeafTurnNo);
        $this->assertSame([], $tree->activePathTurnNos);
    }

    public function testLinearStreamWithUserPrompts(): void
    {
        $events = [
            $this->runEvent('run_started', 1, 0, [
                'payload' => ['messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
                ]],
            ]),
            $this->turnAdvanced(2, 1),
            $this->leafSet(3, 1, 'continue'),
            $this->runEvent('llm_step_completed', 4, 1, ['text' => 'Hi!']),
            $this->runEvent('agent_command_applied', 5, 1, [
                'kind' => 'follow_up',
                'text' => 'Write a test.',
            ]),
            $this->turnAdvanced(6, 2),
            $this->leafSet(7, 2, 'continue'),
            $this->runEvent('llm_step_completed', 8, 2, ['text' => 'Here is a test...']),
        ];

        $tree = $this->projector->build($this->runId, $events);

        $this->assertSame([1, 2], $tree->activePathTurnNos);
        $this->assertSame(2, $tree->currentLeafTurnNo);
        $this->assertSame('user', $tree->nodesByTurnNo[1]->displayRole);
        $this->assertSame('user', $tree->nodesByTurnNo[2]->displayRole);
        $this->assertStringContainsString('Hello', $tree->nodesByTurnNo[1]->title);
        $this->assertStringContainsString('Write a test', $tree->nodesByTurnNo[2]->title);
        $this->assertSame('Write a test.', $tree->nodesByTurnNo[2]->fullPromptText);
        $this->assertSame([2], $tree->nodesByTurnNo[1]->childTurnNos);
        $this->assertSame(1, $tree->nodesByTurnNo[2]->parentTurnNo);
    }

    /**
     * Thesis: history_tail_discarded must remove later active turns from projection
     * so hot prompt/transcript/resume cannot resurrect the abandoned future.
     */
    public function testHistoryTailDiscardRemovesForwardTurns(): void
    {
        $events = [
            $this->turnAdvanced(1, 1),
            $this->leafSet(2, 1, 'continue'),
            $this->turnAdvanced(3, 2),
            $this->leafSet(4, 2, 'continue'),
            $this->turnAdvanced(5, 3),
            $this->leafSet(6, 3, 'continue'),
            $this->leafSet(7, 1, 'history_select'),
            $this->historyTailDiscarded(8, 1),
            $this->turnAdvanced(9, 4),
            $this->leafSet(10, 4, 'continue'),
        ];

        $tree = $this->projector->build($this->runId, $events);

        $this->assertSame([1, 4], $tree->activePathTurnNos);
        $this->assertSame(4, $tree->currentLeafTurnNo);
        $this->assertArrayNotHasKey(2, $tree->nodesByTurnNo);
        $this->assertArrayNotHasKey(3, $tree->nodesByTurnNo);
        $this->assertSame([4], $tree->nodesByTurnNo[1]->childTurnNos);
    }

    public function testLeafSetWithoutDiscardKeepsForwardTurns(): void
    {
        $events = [
            $this->turnAdvanced(1, 1),
            $this->leafSet(2, 1, 'continue'),
            $this->turnAdvanced(3, 2),
            $this->leafSet(4, 2, 'continue'),
            $this->leafSet(5, 1, 'history_select'),
        ];

        $tree = $this->projector->build($this->runId, $events);

        $this->assertSame([1, 2], $tree->activePathTurnNos);
        $this->assertSame(1, $tree->currentLeafTurnNo);
        $this->assertTrue($tree->nodesByTurnNo[1]->isCurrentLeaf);
        $this->assertFalse($tree->nodesByTurnNo[2]->isCurrentLeaf);
    }

    public function testActivePathToReturnsPrefix(): void
    {
        $events = [
            $this->turnAdvanced(1, 1),
            $this->turnAdvanced(2, 2),
            $this->turnAdvanced(3, 3),
        ];
        $tree = $this->projector->build($this->runId, $events);

        $this->assertSame([1, 2], TurnTreeProjector::activePathTo(2, $tree->nodesByTurnNo));
        $this->assertSame([1, 2, 3], TurnTreeProjector::activePathTo(3, $tree->nodesByTurnNo));
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
        return $this->runEvent(RunEventTypeEnum::TurnAdvanced->value, $seq, $turnNo, [
            'turn_no' => $turnNo,
            'step_id' => 'step-'.$turnNo,
        ]);
    }

    private function leafSet(int $seq, int $turnNo, string $reason): RunEvent
    {
        return $this->runEvent(RunEventTypeEnum::LeafSet->value, $seq, $turnNo, [
            'turn_no' => $turnNo,
            'reason' => $reason,
        ]);
    }

    private function historyTailDiscarded(int $seq, int $afterTurnNo): RunEvent
    {
        return $this->runEvent(RunEventTypeEnum::HistoryTailDiscarded->value, $seq, $afterTurnNo, [
            'after_turn_no' => $afterTurnNo,
            'reason' => 'mutate_behind_tip',
        ]);
    }
}
