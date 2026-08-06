<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Replay;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\Replay\TurnTreeReplayFilter;
use Ineersa\CodingAgent\Session\TurnTree\TurnTreeProjector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TurnTreeReplayFilter::class)]
final class TurnTreeReplayFilterTest extends TestCase
{
    private TurnTreeReplayFilter $filter;
    private string $runId = 'run-filter-test';

    protected function setUp(): void
    {
        $this->filter = new TurnTreeReplayFilter(new TurnTreeProjector());
    }

    /**
     * Thesis: without discard/projection filtering, selecting earlier prompt then
     * mutating would resurrect abandoned future events into hot prompt/transcript.
     */
    #[Test]
    public function testFilterExcludesDiscardedTurnsAfterHistoryTailDiscard(): void
    {
        $events = $this->linearDiscardFixture();

        $result = $this->filter->filter($this->runId, $events);
        $seqs = array_map(static fn (RunEvent $e): int => $e->seq, $result->events);

        $this->assertSame([1, 3], $result->activePathTurnNos);
        $this->assertContains(1, $seqs, 'run_started');
        $this->assertContains(2, $seqs, 'turn_advanced 1');
        $this->assertContains(4, $seqs, 'llm turn 1');
        $this->assertNotContains(6, $seqs, 'discarded turn-2 seed command');
        $this->assertNotContains(8, $seqs, 'discarded turn_advanced 2');
        $this->assertNotContains(10, $seqs, 'discarded llm turn 2');
        $this->assertContains(12, $seqs, 'history_tail_discarded');
        $this->assertContains(13, $seqs, 'turn_advanced 3');
        $this->assertContains(15, $seqs, 'llm turn 3');
    }

    #[Test]
    public function testFilterForLeafPrefixBeforeMutationKeepsForwardUntilDiscard(): void
    {
        // After history_select leaf_set to turn 1, before discard, tip is 1 but turn 2 still active.
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value, []),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event(3, 1, RunEventTypeEnum::LeafSet->value, ['turn_no' => 1, 'reason' => 'continue']),
            $this->event(4, 1, RunEventTypeEnum::LlmStepCompleted->value, ['text' => 'A']),
            $this->event(5, 1, RunEventTypeEnum::AgentEnd->value, []),
            $this->event(6, 1, RunEventTypeEnum::AgentCommandQueued->value, ['kind' => 'follow_up', 'text' => 'B']),
            $this->event(7, 1, RunEventTypeEnum::AgentCommandApplied->value, ['kind' => 'follow_up', 'text' => 'B']),
            $this->event(8, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event(9, 2, RunEventTypeEnum::LeafSet->value, ['turn_no' => 2, 'reason' => 'continue']),
            $this->event(10, 2, RunEventTypeEnum::LlmStepCompleted->value, ['text' => 'B-ans']),
            $this->event(11, 1, RunEventTypeEnum::LeafSet->value, [
                'turn_no' => 1,
                'reason' => 'history_select',
                'selected_prompt_turn_no' => 2,
            ]),
        ];

        $result = $this->filter->filterForLeaf($this->runId, $events, 1);
        $seqs = array_map(static fn (RunEvent $e): int => $e->seq, $result->events);

        $this->assertSame([1], $result->activePathTurnNos);
        $this->assertNotContains(6, $seqs, 'seed for turn 2 excluded when replaying tip 1');
        $this->assertNotContains(8, $seqs);
        $this->assertNotContains(10, $seqs);
        $this->assertContains(11, $seqs, 'history_select leaf_set kept as metadata');
    }

    /**
     * @return list<RunEvent>
     */
    private function linearDiscardFixture(): array
    {
        return [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value, []),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $this->event(3, 1, RunEventTypeEnum::LeafSet->value, ['turn_no' => 1, 'reason' => 'continue']),
            $this->event(4, 1, RunEventTypeEnum::LlmStepCompleted->value, ['text' => 'A']),
            $this->event(5, 1, RunEventTypeEnum::AgentEnd->value, []),
            $this->event(6, 1, RunEventTypeEnum::AgentCommandQueued->value, ['kind' => 'follow_up', 'text' => 'B']),
            $this->event(7, 1, RunEventTypeEnum::AgentCommandApplied->value, ['kind' => 'follow_up', 'text' => 'B']),
            $this->event(8, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            $this->event(9, 2, RunEventTypeEnum::LeafSet->value, ['turn_no' => 2, 'reason' => 'continue']),
            $this->event(10, 2, RunEventTypeEnum::LlmStepCompleted->value, ['text' => 'B-ans']),
            $this->event(11, 1, RunEventTypeEnum::LeafSet->value, [
                'turn_no' => 1,
                'reason' => 'history_select',
                'selected_prompt_turn_no' => 2,
            ]),
            $this->event(12, 1, RunEventTypeEnum::HistoryTailDiscarded->value, [
                'after_turn_no' => 1,
                'reason' => 'mutate_behind_tip',
            ]),
            $this->event(13, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3]),
            $this->event(14, 3, RunEventTypeEnum::LeafSet->value, ['turn_no' => 3, 'reason' => 'continue']),
            $this->event(15, 3, RunEventTypeEnum::LlmStepCompleted->value, ['text' => 'C']),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(int $seq, int $turnNo, string $type, array $payload): RunEvent
    {
        return new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
        );
    }
}
