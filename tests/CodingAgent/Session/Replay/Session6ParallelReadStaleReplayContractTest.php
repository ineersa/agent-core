<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Replay;

use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use PHPUnit\Framework\TestCase;

final class Session6ParallelReadStaleReplayContractTest extends TestCase
{
    public function testStaleIgnoredMustClearUnresolvedPendingToolCallOnReplay(): void
    {
        $runId = '6';
        $events = $this->session6ParallelReadTailEvents($runId);
        $rebuilt = (new RunStateReducer())->replay(RunState::queued($runId), $events);

        $this->assertSame(RunStatus::Running, $rebuilt->status);
        $this->assertArrayNotHasKey(
            'call_01_rLANd47jEeVBDpcoUeqP8790',
            $rebuilt->pendingToolCalls,
            'stale_result_ignored must clear unresolved pending tool calls (session 6 contract)',
        );
    }

    /** @return list<RunEvent> */
    private function session6ParallelReadTailEvents(string $runId): array
    {
        $base = [
            ['seq' => 1, 'type' => 'run_started', 'payload' => ['step_id' => 's1', 'payload' => ['messages' => []]]],
            ['seq' => 65, 'type' => 'tool_execution_start', 'payload' => ['tool_call_id' => 'call_00_etfn1vuDKrEmxBSHfK0q3613', 'tool_name' => 'read']],
            ['seq' => 66, 'type' => 'tool_execution_start', 'payload' => ['tool_call_id' => 'call_01_rLANd47jEeVBDpcoUeqP8790', 'tool_name' => 'read']],
            ['seq' => 67, 'type' => 'tool_call_result_received', 'payload' => ['tool_call_id' => 'call_00_etfn1vuDKrEmxBSHfK0q3613', 'is_error' => false]],
            ['seq' => 68, 'type' => 'tool_execution_end', 'payload' => ['tool_call_id' => 'call_00_etfn1vuDKrEmxBSHfK0q3613', 'is_error' => false, 'result' => '{}']],
            ['seq' => 69, 'type' => 'stale_result_ignored', 'payload' => [
                'result' => 'tool_call_result',
                'tool_call_id' => 'call_01_rLANd47jEeVBDpcoUeqP8790',
                'reason' => 'untracked_tool_call',
            ]],
        ];
        $out = [];
        foreach ($base as $row) {
            $out[] = new RunEvent(runId: $runId, seq: $row['seq'], turnNo: 7, type: $row['type'], payload: $row['payload']);
        }

        return $out;
    }
}
