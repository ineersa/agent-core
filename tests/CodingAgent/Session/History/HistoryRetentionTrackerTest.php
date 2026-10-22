<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Session\History\HistoryRetentionTracker;
use PHPUnit\Framework\TestCase;

final class HistoryRetentionTrackerTest extends TestCase
{
    public function testAdvancesPreserveInsertionOrderAndDoNotDuplicateTurns(): void
    {
        $tracker = new HistoryRetentionTracker();
        foreach ([7, 2, 9, 2, 0, -1] as $turn) {
            $tracker->observe(RunEvent::forAppend('run', $turn, 'turn_advanced'));
        }
        $this->assertSame([7, 2, 9], $tracker->retainedTurnNos);
        $this->assertSame(2, $tracker->positionTurnNo);
        $tracker->observe(RunEvent::forAppend('run', 99, 'turn_advanced', ['turn_no' => 7]));
        $this->assertSame([7, 2, 9], $tracker->retainedTurnNos);
        $this->assertSame(7, $tracker->positionTurnNo);
    }

    public function testPositionMarkersOnlySelectRetainedTurnsOrZero(): void
    {
        $tracker = new HistoryRetentionTracker();
        foreach ([7, 2, 9] as $turn) {
            $tracker->observe(RunEvent::forAppend('run', $turn, 'turn_advanced'));
        }
        foreach ([8, -1] as $turn) {
            $tracker->observe(RunEvent::forAppend('run', $turn, 'history_position_set', ['reason' => 'history_select']));
            $this->assertSame(9, $tracker->positionTurnNo);
        }
        foreach ([2, 0, 7, 7] as $turn) {
            $tracker->observe(RunEvent::forAppend('run', 99, 'history_position_set', ['position_turn_no' => $turn, 'reason' => 'continue']));
            $this->assertSame($turn, $tracker->positionTurnNo);
        }
        $this->assertSame([7, 2, 9], $tracker->retainedTurnNos);
    }

    public function testNumericDiscardPreservesOrderAndAllowsReintroducedTurns(): void
    {
        $tracker = new HistoryRetentionTracker();
        foreach ([7, 2, 9] as $turn) {
            $tracker->observe(RunEvent::forAppend('run', $turn, 'turn_advanced'));
        }
        $tracker->observe(RunEvent::forAppend('run', 2, 'history_position_set'));
        $tracker->observe(RunEvent::forAppend('run', 2, 'history_tail_discarded', ['after_turn_no' => 8]));
        $this->assertSame([7, 2], $tracker->retainedTurnNos);
        $this->assertSame(2, $tracker->positionTurnNo);
        $tracker->observe(RunEvent::forAppend('run', 7, 'history_position_set'));
        $tracker->observe(RunEvent::forAppend('run', 7, 'history_tail_discarded', ['after_turn_no' => 3]));
        $this->assertSame([2], $tracker->retainedTurnNos);
        $this->assertSame(2, $tracker->positionTurnNo);
        $tracker->observe(RunEvent::forAppend('run', 9, 'history_position_set'));
        $this->assertSame(2, $tracker->positionTurnNo);
        $tracker->observe(RunEvent::forAppend('run', 9, 'turn_advanced'));
        $this->assertSame([2, 9], $tracker->retainedTurnNos);
        $tracker->observe(RunEvent::forAppend('run', 9, 'history_tail_discarded', ['after_turn_no' => 2]));
        $this->assertSame(2, $tracker->positionTurnNo);
        $tracker->observe(RunEvent::forAppend('run', 2, 'history_tail_discarded', ['after_turn_no' => 0]));
        $this->assertSame([], $tracker->retainedTurnNos);
        $this->assertSame(0, $tracker->positionTurnNo);
        $tracker->observe(RunEvent::forAppend('run', 2, 'turn_advanced'));
        $this->assertSame([2], $tracker->retainedTurnNos);
        $tracker->observe(RunEvent::forAppend('run', 2, 'history_tail_discarded', ['after_turn_no' => -1]));
        $this->assertSame([], $tracker->retainedTurnNos);
        $this->assertSame(0, $tracker->positionTurnNo);
    }

    public function testLongHistoryConfirmationsAndDiscardedMembershipStayConsistent(): void
    {
        $tracker = new HistoryRetentionTracker();
        for ($turn = 1; $turn <= 4096; ++$turn) {
            $tracker->observe(RunEvent::forAppend('run', $turn, 'turn_advanced'));
            $tracker->observe(RunEvent::forAppend('run', $turn, 'history_position_set', ['position_turn_no' => $turn, 'reason' => 'continue']));
        }
        $this->assertSame(range(1, 4096), $tracker->retainedTurnNos);
        $tracker->observe(RunEvent::forAppend('run', 2048, 'history_tail_discarded', ['after_turn_no' => 2048]));
        $tracker->observe(RunEvent::forAppend('run', 3072, 'history_position_set'));
        $this->assertSame(2048, $tracker->positionTurnNo);
        $tracker->observe(RunEvent::forAppend('run', 4096, 'turn_advanced'));
        $this->assertSame([...range(1, 2048), 4096], $tracker->retainedTurnNos);
        $this->assertSame(4096, $tracker->positionTurnNo);
    }
}
