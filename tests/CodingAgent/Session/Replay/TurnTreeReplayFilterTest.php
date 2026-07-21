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

        $this->assertSame([1], $result->activePathTurnNos);
        $this->assertContains(1, $seqs, 'run_started');
        $this->assertContains(2, $seqs, 'turn_advanced turn 1');
        $this->assertContains(3, $seqs, 'leaf_set turn 1');
        $this->assertContains(4, $seqs, 'llm_step_completed turn 1');
        $this->assertContains(5, $seqs, 'agent_end turn 1');
        $this->assertNotContains(6, $seqs, 'abandoned turn-2 seed agent_command_queued must be excluded');
        $this->assertNotContains(7, $seqs, 'abandoned turn-2 seed agent_command_applied must be excluded');
        $this->assertNotContains(8, $seqs, 'turn_advanced turn 2');
        $this->assertNotContains(10, $seqs, 'llm_step_completed turn 2');
        $this->assertContains(12, $seqs, 'rewind leaf_set to turn 1');
    }

    #[Test]
    public function testFilterForLeafForwardLeafTwoIncludesTurnTwoSeedCommands(): void
    {
        $events = $this->livePineappleRewindFixture();

        $result = $this->filter->filterForLeaf($this->runId, $events, 2);

        $seqs = array_map(static fn (RunEvent $e): int => $e->seq, $result->events);

        $this->assertSame([1, 2], $result->activePathTurnNos);
        $this->assertContains(6, $seqs);
        $this->assertContains(7, $seqs);
        $this->assertContains(8, $seqs);
        $this->assertContains(10, $seqs);
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

        $this->assertSame([1, 2], $result->activePathTurnNos);
        $this->assertContains(4, $seqs);
        $this->assertContains(5, $seqs);
        $this->assertNotContains(8, $seqs);
        $this->assertNotContains(9, $seqs);
    }

    #[Test]
    public function testFilterForLeafRewindsTerminalShellChildAndKeepsModelBash(): void
    {
        // Thesis: terminal bang shells seed a child turn (command anchor on parent,
        // tool lifecycle on child). Generic command-to-next-TurnAdvanced exclusion
        // drops the abandoned shell command+output on rewind while model bash on
        // the active parent path remains.
        $events = [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value, []),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1, 'parent_turn_no' => null]),
            $this->event(3, 1, RunEventTypeEnum::LeafSet->value, ['turn_no' => 1, 'reason' => 'continue']),
            $this->event(4, 1, RunEventTypeEnum::LlmStepCompleted->value, []),
            $this->event(5, 1, RunEventTypeEnum::AgentEnd->value, []),
            // Model-generated bash on the parent conversational turn.
            $this->event(6, 1, RunEventTypeEnum::ToolExecutionStart->value, [
                'tool_call_id' => 'model-bash',
                'tool_name' => 'bash',
            ]),
            $this->event(7, 1, RunEventTypeEnum::ToolExecutionEnd->value, [
                'tool_call_id' => 'model-bash',
                'tool_name' => 'bash',
                'result' => 'model output',
            ]),
            // Terminal bang shell: parent command anchor then child turn ownership.
            $this->event(8, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'shell_command',
                'text' => '!printf BANG_CHILD',
            ]),
            $this->event(9, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2, 'parent_turn_no' => 1]),
            $this->event(10, 2, RunEventTypeEnum::LeafSet->value, [
                'turn_no' => 2,
                'previous_turn_no' => 1,
                'parent_turn_no' => 1,
                'reason' => 'shell_command',
            ]),
            $this->event(11, 2, RunEventTypeEnum::ToolExecutionStart->value, [
                'tool_call_id' => 'shell-child',
                'tool_name' => 'bash',
            ]),
            $this->event(12, 2, RunEventTypeEnum::ToolExecutionEnd->value, [
                'tool_call_id' => 'shell-child',
                'tool_name' => 'bash',
                'result' => 'BANG_CHILD',
            ]),
            $this->event(13, 0, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
            $this->event(14, 1, RunEventTypeEnum::LeafSet->value, ['turn_no' => 1, 'reason' => 'rewind']),
        ];

        $active = $this->filter->filterForLeaf($this->runId, $events, 2);
        $activeSeqs = array_map(static fn (RunEvent $e): int => $e->seq, $active->events);
        $this->assertContains(8, $activeSeqs, 'shell command anchor remains on active child path');
        $this->assertContains(11, $activeSeqs, 'shell tool start on child remains');
        $this->assertContains(12, $activeSeqs, 'shell tool end on child remains');
        $this->assertContains(6, $activeSeqs, 'model bash remains on parent path');

        $rewound = $this->filter->filterForLeaf($this->runId, $events, 1);
        $rewoundSeqs = array_map(static fn (RunEvent $e): int => $e->seq, $rewound->events);
        $this->assertNotContains(8, $rewoundSeqs, 'abandoned shell command anchor excluded');
        $this->assertNotContains(9, $rewoundSeqs, 'abandoned shell child turn excluded');
        $this->assertNotContains(11, $rewoundSeqs, 'abandoned shell tool start excluded');
        $this->assertNotContains(12, $rewoundSeqs, 'abandoned shell tool end excluded');
        $this->assertContains(6, $rewoundSeqs, 'model bash on parent retained');
        $this->assertContains(7, $rewoundSeqs, 'model bash output on parent retained');
        $this->assertContains(13, $rewoundSeqs, 'run-level agent_end retained');
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

        $this->assertContains(4, $seqs);
        $this->assertContains(5, $seqs);
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
