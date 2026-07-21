<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Replay;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\Replay\RewindBoundaryPolicy;
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
        $this->filter = new TurnTreeReplayFilter(new TurnTreeProjector(), new RewindBoundaryPolicy());
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
    public function testFilterForLeafKeepsActiveDirectShellsButDropsAbandonedCommandsAndOutputAfterRewind(): void
    {
        $events = $this->directShellRewindFixture();

        $activeResult = $this->filter->filterForLeaf($this->runId, $events, 2);
        $activeSeqs = array_map(static fn (RunEvent $event): int => $event->seq, $activeResult->events);

        // Both bangs on turn 1 and the bang on turn 2 are active on the turn-2 path.
        $this->assertContains(6, $activeSeqs);
        $this->assertContains(7, $activeSeqs);
        $this->assertContains(9, $activeSeqs);
        $this->assertContains(10, $activeSeqs);
        $this->assertContains(15, $activeSeqs);
        $this->assertContains(16, $activeSeqs);
        $this->assertContains(17, $activeSeqs);
        // A model-generated bash lifecycle event has no shell-command anchor and
        // must not be filtered by the direct-shell replay rule.
        $this->assertContains(18, $activeSeqs);
        $this->assertContains(19, $activeSeqs);

        $rewoundResult = $this->filter->filterForLeaf($this->runId, $events, 1);
        $rewoundSeqs = array_map(static fn (RunEvent $event): int => $event->seq, $rewoundResult->events);

        // Bangs submitted after turn 1 completed are abandoned even though their
        // command events carry turnNo=1, and all correlated output disappears too.
        $this->assertNotContains(6, $rewoundSeqs);
        $this->assertNotContains(7, $rewoundSeqs);
        $this->assertNotContains(8, $rewoundSeqs);
        $this->assertNotContains(9, $rewoundSeqs);
        $this->assertNotContains(10, $rewoundSeqs);
        $this->assertNotContains(15, $rewoundSeqs);
        $this->assertNotContains(16, $rewoundSeqs);
        $this->assertNotContains(19, $rewoundSeqs);
        $this->assertContains(17, $rewoundSeqs, 'model-generated bash remains replayable');
        $this->assertContains(18, $rewoundSeqs, 'model-generated bash output remains replayable');
        $this->assertContains(21, $rewoundSeqs, 'run-level terminal events remain replayable');
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
    private function directShellRewindFixture(): array
    {
        return [
            $this->event(1, 0, RunEventTypeEnum::RunStarted->value, []),
            $this->event(2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1, 'parent_turn_no' => null]),
            $this->event(3, 1, RunEventTypeEnum::LeafSet->value, ['turn_no' => 1, 'reason' => 'continue']),
            $this->event(4, 1, RunEventTypeEnum::LlmStepCompleted->value, []),
            $this->event(5, 1, RunEventTypeEnum::AgentEnd->value, []),
            $this->event(6, 1, RunEventTypeEnum::AgentCommandApplied->value, $this->shellPayload('A', 'shell-a')),
            $this->event(7, 1, RunEventTypeEnum::ToolExecutionStart->value, $this->shellLifecyclePayload('shell-a')),
            $this->event(8, 1, RunEventTypeEnum::ToolExecutionEnd->value, $this->shellLifecyclePayload('shell-a') + ['result' => 'A']),
            $this->event(9, 1, RunEventTypeEnum::AgentCommandApplied->value, $this->shellPayload('B', 'shell-b')),
            $this->event(10, 1, RunEventTypeEnum::ToolExecutionEnd->value, $this->shellLifecyclePayload('shell-b') + ['result' => 'B']),
            $this->event(11, 1, RunEventTypeEnum::AgentCommandApplied->value, ['kind' => 'follow_up', 'text' => 'next']),
            $this->event(12, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2, 'parent_turn_no' => 1]),
            $this->event(13, 2, RunEventTypeEnum::LeafSet->value, ['turn_no' => 2, 'reason' => 'continue']),
            $this->event(14, 2, RunEventTypeEnum::AgentEnd->value, []),
            $this->event(15, 2, RunEventTypeEnum::AgentCommandApplied->value, $this->shellPayload('C', 'shell-c')),
            $this->event(16, 2, RunEventTypeEnum::ToolExecutionStart->value, $this->shellLifecyclePayload('shell-c')),
            $this->event(17, 0, RunEventTypeEnum::ToolExecutionStart->value, [
                'tool_call_id' => 'model-bash',
                'tool_name' => 'bash',
            ]),
            $this->event(18, 0, RunEventTypeEnum::ToolExecutionEnd->value, [
                'tool_call_id' => 'model-bash',
                'tool_name' => 'bash',
                'result' => 'model output',
            ]),
            $this->event(19, 2, RunEventTypeEnum::ToolExecutionEnd->value, $this->shellLifecyclePayload('shell-c') + ['result' => 'C']),
            $this->event(20, 1, RunEventTypeEnum::LeafSet->value, ['turn_no' => 1, 'reason' => 'rewind']),
            $this->event(21, 0, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ];
    }

    /** @return array<string, mixed> */
    private function shellPayload(string $command, string $toolCallId): array
    {
        return [
            'kind' => 'shell_command',
            'text' => '!printf '.$command,
            'command' => 'printf '.$command,
            'tool_call_id' => $toolCallId,
        ];
    }

    /** @return array<string, mixed> */
    private function shellLifecyclePayload(string $toolCallId): array
    {
        return [
            'tool_call_id' => $toolCallId,
            'tool_name' => 'bash',
        ];
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
