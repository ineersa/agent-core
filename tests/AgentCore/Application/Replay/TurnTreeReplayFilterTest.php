<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Replay;

use Ineersa\AgentCore\Application\Replay\TurnTreeReplayFilter;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\TurnTreeProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TurnTreeReplayFilterTest extends TestCase
{
    private TurnTreeReplayFilter $filter;
    private string $runId = 'run-filter-test';

    protected function setUp(): void
    {
        $this->filter = new TurnTreeReplayFilter(new TurnTreeProjector());
    }

    /**
     * Thesis: rewinding to turn 1 must not replay turn-2 seed commands stamped turn_no=1
     * (agent_command_queued/applied before turn_advanced creates turn 2). Without the
     * created-turn guard, "pineapple" user input leaks into the active-path replay set.
     */
    #[Test]
    public function testFilterForLeafRewindToTurnOneExcludesAbandonedTurnTwoSeedCommands(): void
    {
        $events = $this->livePineappleRewindFixture();

        $result = $this->filter->filterForLeaf($this->runId, $events, 1);

        $seqs = array_map(static fn (RunEvent $e): int => $e->seq, $result->events);

        self::assertSame([1], $result->activePathTurnNos);
        self::assertContains(1, $seqs, 'run_started');
        self::assertContains(2, $seqs, 'turn_advanced turn 1');
        self::assertContains(3, $seqs, 'leaf_set turn 1');
        self::assertContains(4, $seqs, 'llm_step_completed turn 1');
        self::assertContains(5, $seqs, 'agent_end turn 1');
        self::assertNotContains(6, $seqs, 'abandoned turn-2 seed agent_command_queued must be excluded');
        self::assertNotContains(7, $seqs, 'abandoned turn-2 seed agent_command_applied must be excluded');
        self::assertNotContains(8, $seqs, 'turn_advanced turn 2');
        self::assertNotContains(10, $seqs, 'llm_step_completed turn 2');
        self::assertContains(12, $seqs, 'rewind leaf_set to turn 1');
    }

    #[Test]
    public function testFilterForLeafForwardLeafTwoIncludesTurnTwoSeedCommands(): void
    {
        $events = $this->livePineappleRewindFixture();

        $result = $this->filter->filterForLeaf($this->runId, $events, 2);

        $seqs = array_map(static fn (RunEvent $e): int => $e->seq, $result->events);

        self::assertSame([1, 2], $result->activePathTurnNos);
        self::assertContains(6, $seqs);
        self::assertContains(7, $seqs);
        self::assertContains(8, $seqs);
        self::assertContains(10, $seqs);
    }

    #[Test]
    public function testFilterForLeafBranchingExcludesSiblingTurnThreeSeed(): void
    {
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value, []),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1, 'parent_turn_no' => null]),
            $this->event(3, 1, RunEventTypeEnum::LeafSet->value, ['turn_no' => 1, 'reason' => 'continue']),
            $this->event(4, 1, RunEventTypeEnum::AgentCommandQueued->value, ['kind' => 'follow_up', 'text' => 'branch-2']),
            $this->event(5, 1, RunEventTypeEnum::AgentCommandApplied->value, ['kind' => 'follow_up', 'text' => 'branch-2']),
            $this->event(6, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2, 'parent_turn_no' => 1]),
            $this->event(7, 2, RunEventTypeEnum::LeafSet->value, ['turn_no' => 2, 'reason' => 'continue']),
            $this->event(8, 1, RunEventTypeEnum::AgentCommandQueued->value, ['kind' => 'follow_up', 'text' => 'branch-3']),
            $this->event(9, 1, RunEventTypeEnum::AgentCommandApplied->value, ['kind' => 'follow_up', 'text' => 'branch-3']),
            $this->event(10, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3, 'parent_turn_no' => 1]),
            $this->event(11, 3, RunEventTypeEnum::LeafSet->value, ['turn_no' => 3, 'reason' => 'continue']),
            $this->event(12, 2, RunEventTypeEnum::LeafSet->value, ['turn_no' => 2, 'reason' => 'rewind']),
        ];

        $result = $this->filter->filterForLeaf($this->runId, $events, 2);

        $seqs = array_map(static fn (RunEvent $e): int => $e->seq, $result->events);

        self::assertSame([1, 2], $result->activePathTurnNos);
        self::assertContains(4, $seqs);
        self::assertContains(5, $seqs);
        self::assertNotContains(8, $seqs);
        self::assertNotContains(9, $seqs);
    }

    #[Test]
    public function testFilterForLeafIncludesUnmappedActiveLeafCommand(): void
    {
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value, []),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1, 'parent_turn_no' => null]),
            $this->event(3, 1, RunEventTypeEnum::LeafSet->value, ['turn_no' => 1, 'reason' => 'continue']),
            $this->event(4, 1, RunEventTypeEnum::AgentCommandQueued->value, ['kind' => 'follow_up', 'text' => 'draft']),
            $this->event(5, 1, RunEventTypeEnum::AgentCommandApplied->value, ['kind' => 'follow_up', 'text' => 'draft']),
        ];

        $result = $this->filter->filterForLeaf($this->runId, $events, 1);

        $seqs = array_map(static fn (RunEvent $e): int => $e->seq, $result->events);

        self::assertContains(4, $seqs);
        self::assertContains(5, $seqs);
    }

    /** @return list<RunEvent> */
    private function livePineappleRewindFixture(): array
    {
        return [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value, ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Remember']]]]]),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1, 'parent_turn_no' => null, 'step_id' => 's1']),
            $this->event(3, 1, RunEventTypeEnum::LeafSet->value, ['turn_no' => 1, 'reason' => 'continue']),
            $this->event(4, 1, RunEventTypeEnum::LlmStepCompleted->value, ['text' => 'OK', 'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'OK']]]]),
            $this->event(5, 1, RunEventTypeEnum::AgentEnd->value, ['status' => 'completed']),
            $this->event(6, 1, RunEventTypeEnum::AgentCommandQueued->value, ['kind' => 'follow_up', 'text' => 'The secret word is pineapple']),
            $this->event(7, 1, RunEventTypeEnum::AgentCommandApplied->value, ['kind' => 'follow_up', 'text' => 'The secret word is pineapple']),
            $this->event(8, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2, 'parent_turn_no' => 1, 'step_id' => 's2']),
            $this->event(9, 2, RunEventTypeEnum::LeafSet->value, ['turn_no' => 2, 'reason' => 'continue']),
            $this->event(10, 2, RunEventTypeEnum::LlmStepCompleted->value, ['text' => 'Noted', 'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Noted']]]]),
            $this->event(11, 2, RunEventTypeEnum::AgentEnd->value, ['status' => 'completed']),
            $this->event(12, 1, RunEventTypeEnum::LeafSet->value, ['turn_no' => 1, 'reason' => 'rewind']),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function event(int $seq, int $turnNo, string $type, array $payload): RunEvent
    {
        return new RunEvent(runId: $this->runId, seq: $seq, turnNo: $turnNo, type: $type, payload: $payload);
    }
}
