<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Session\History\HistoryReplayPlan;
use PHPUnit\Framework\TestCase;

final class HistoryReplayPlanTest extends TestCase
{
    public function testSparseSelectionDiscardAndCommandSuppression(): void
    {
        $events = [
            $this->event(1, 0, 'run_started'),
            $this->event(2, 2, 'turn_advanced', ['turn_no' => 2]),
            $this->event(3, 2, 'llm_step_completed'),
            $this->event(4, 2, 'agent_command_queued'),
            $this->event(5, 7, 'turn_advanced', ['turn_no' => 7]),
            $this->event(6, 7, 'compaction_completed'),
            $this->event(7, 2, 'history_position_set', ['position_turn_no' => 2, 'reason' => 'history_select']),
            $this->event(8, 2, 'history_tail_discarded', ['after_turn_no' => 2]),
            $this->event(9, 11, 'turn_advanced', ['turn_no' => 11]),
            $this->event(10, 11, 'agent_end'),
            $this->event(11, 11, 'agent_command_applied'),
            $this->event(12, 11, 'compaction_completed'),
            $this->event(13, 11, 'history_position_set', ['position_turn_no' => 11, 'reason' => 'history_select']),
            $this->event(14, 0, 'agent_command_queued'),
        ];
        $plan = HistoryReplayPlan::build((static function () use ($events): \Generator { yield from $events; })());
        $this->assertSame(11, $plan->positionTurnNo);
        $this->assertSame([1, 2, 3, 7, 8, 9, 10, 12, 13, 14], array_values(array_map(static fn (RunEvent $e): int => $e->seq, array_filter($events, $plan->includes(...)))));
        $selected = HistoryReplayPlan::build($events, 2);
        $this->assertFalse($selected->includes($events[8]));
        $this->assertTrue($selected->includes($events[12]));
    }

    public function testDecodedPayloadsAreReleasedDuringAndAfterPlanning(): void
    {
        $previous = null;
        $source = (function () use (&$previous): \Generator {
            for ($seq = 1; $seq <= 100; ++$seq) {
                if (null !== $previous && $seq > 2) {
                    self::assertNull($previous->get());
                }
                $event = $this->event($seq, 2, 'agent_command_applied', ['body' => str_repeat('x', 1024 * 1024)]);
                $previous = \WeakReference::create($event);
                yield $event;
                unset($event);
                // foreach keeps its current event until the next value is assigned;
                // advance with a small event before checking the released payload.
                ++$seq;
                yield $this->event($seq, 2, 'llm_step_completed');
            }
        })();
        $plan = HistoryReplayPlan::build($source);
        $this->assertNull($previous->get());
        $this->assertSame(0, $plan->positionTurnNo);
    }

    public function testInvalidArchiveOrderFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        HistoryReplayPlan::build([$this->event(2, 0, 'run_started'), $this->event(1, 2, 'turn_advanced')]);
    }

    public function testSelectedPrefixUsesAnchorOrderAndRejectsAbsentAnchor(): void
    {
        $events = [
            $this->event(1, 7, 'turn_advanced'),
            $this->event(2, 2, 'turn_advanced'),
            $this->event(3, 9, 'turn_advanced'),
        ];
        $plan = HistoryReplayPlan::build($events, 2);
        $this->assertTrue($plan->includes($events[0]));
        $this->assertTrue($plan->includes($events[1]));
        $this->assertFalse($plan->includes($events[2]));
        $absent = HistoryReplayPlan::build($events, 8);
        foreach ($events as $event) {
            $this->assertFalse($absent->includes($event));
        }
    }

    public function testDuplicateSequencesFailClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        HistoryReplayPlan::build([$this->event(1, 0, 'run_started'), $this->event(1, 2, 'turn_advanced')]);
    }

    public function testMixedRunIdentityFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        HistoryReplayPlan::build([$this->event(1, 0, 'run_started'), new RunEvent('other', 2, 2, 'turn_advanced', [], new \DateTimeImmutable())]);
    }

    /** @param array<string, mixed> $payload */
    private function event(int $seq, int $turn, string $type, array $payload = []): RunEvent
    {
        return new RunEvent('run', $seq, $turn, $type, $payload, new \DateTimeImmutable());
    }
}
