<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HistoryProjectorTest extends TestCase
{
    private HistoryProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new HistoryProjector();
    }

    #[Test]
    public function testEmptyStream(): void
    {
        $history = $this->projector->build('run-1', []);
        $this->assertSame([], $history->turns);
        $this->assertNull($history->positionTurnNo);
        $this->assertSame([], $history->retainedTurnNos());
    }

    #[Test]
    public function testOrderedRetainedTurnsAndPosition(): void
    {
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value, [
                'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]]]],
            ]),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1, 'step_id' => 'follow_up-1']),
            $this->event(3, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1, 'reason' => 'continue']),
            $this->event(4, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'text' => 'Write a test.',
            ]),
            $this->event(5, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2, 'step_id' => 'follow_up-2']),
            $this->event(6, 2, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 2, 'reason' => 'continue']),
        ];

        $history = $this->projector->build('run-1', $events);
        $this->assertSame([1, 2], $history->retainedTurnNos());
        $this->assertSame(2, $history->positionTurnNo);
        $this->assertSame('user', $history->turns[0]->displayRole);
        $this->assertSame('user', $history->turns[1]->displayRole);
        $this->assertStringContainsString('Hello', $history->turns[0]->title);
        $this->assertStringContainsString('Write a test', $history->turns[1]->title);
        $this->assertSame('Write a test.', $history->turns[1]->promptText);
        $this->assertSame(1, $history->predecessorTurnNo(2));
        $this->assertSame(0, $history->predecessorTurnNo(1));
    }

    #[Test]
    public function testTailDiscardRemovesForwardTurns(): void
    {
        $events = [
            $this->event(1, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1, 'step_id' => 'follow_up-1']),
            $this->event(2, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
            $this->event(3, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2, 'step_id' => 'follow_up-2']),
            $this->event(4, 2, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 2]),
            $this->event(5, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3, 'step_id' => 'follow_up-3']),
            $this->event(6, 3, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 3]),
            $this->event(7, 1, RunEventTypeEnum::HistoryTailDiscarded->value, ['after_turn_no' => 1]),
            $this->event(8, 4, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 4, 'step_id' => 'follow_up-4']),
            $this->event(9, 4, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 4]),
        ];

        $history = $this->projector->build('run-1', $events);
        $this->assertSame([1, 4], $history->retainedTurnNos());
        $this->assertSame(4, $history->positionTurnNo);
        $this->assertNull($history->turn(2));
        $this->assertNull($history->turn(3));
    }

    #[Test]
    public function testPositionSetSelectsWithoutDiscard(): void
    {
        $events = [
            $this->event(1, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1, 'step_id' => 'follow_up-1']),
            $this->event(2, 1, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 1]),
            $this->event(3, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2, 'step_id' => 'follow_up-2']),
            $this->event(4, 2, RunEventTypeEnum::HistoryPositionSet->value, ['position_turn_no' => 2]),
            $this->event(5, 1, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 1,
                'reason' => 'history_select',
            ]),
        ];

        $history = $this->projector->build('run-1', $events);
        $this->assertSame([1, 2], $history->retainedTurnNos());
        $this->assertSame(1, $history->positionTurnNo);
        $this->assertSame([1], $history->retainedTurnNosThrough(1));
        $this->assertSame([1, 2], $history->retainedTurnNosThrough(2));
        $this->assertSame([], $history->retainedTurnNosThrough(0));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(int $seq, int $turnNo, string $type, array $payload = []): RunEvent
    {
        return new RunEvent(
            runId: 'run-1',
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
            createdAt: new \DateTimeImmutable(),
        );
    }
}
