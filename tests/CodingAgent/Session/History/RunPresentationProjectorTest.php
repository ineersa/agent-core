<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayAssistantMessageFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Session\History\HistoryReplayPlan;
use Ineersa\CodingAgent\Session\History\RunPresentationDTO;
use Ineersa\CodingAgent\Session\History\RunPresentationProjector;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class RunPresentationProjectorTest extends IsolatedKernelTestCase
{
    public function testIncrementalTerminalReductionRetainsOnlyOrdinaryToolCounts(): void
    {
        $codec = self::getContainer()->get(ToolExecutionEndPayloadCodec::class);
        $projector = new RunPresentationProjector(new ReplayAssistantMessageFactory(), $codec, 0, 800);
        $projector->observe(RunEvent::forAppend('presentation', 0, 'run_started', ['payload' => ['messages' => [
            $this->message('system', 'SYSTEM_SECRET'),
            $this->message('user', 'Initial'),
        ]]]));
        $assistant = $this->message('assistant', 'Partial answer');
        $assistant['tool_calls'] = [['id' => 'ordinary', 'function' => ['name' => 'read', 'arguments' => '{}']]];
        $projector->observe(RunEvent::forAppend('presentation', 1, 'llm_step_completed', ['assistant_message' => $assistant]));
        $projector->observe(RunEvent::forAppend('presentation', 1, 'agent_command_applied', ['kind' => 'shell_command', 'idempotency_key' => 'shell-key']));
        foreach (['sh_'.hash('sha256', 'shell-key'), 'ordinary'] as $id) {
            $projector->observe(RunEvent::forAppend('presentation', 1, 'tool_execution_end', $codec->toEventPayload(new ToolCallResult(
                'presentation', 1, 'step', 1, 'result-'.$id, $id, 0, ['tool_name' => 'read', 'output' => 'RESULT_SECRET'],
            ))));
        }
        $projector->observe(RunEvent::forAppend('presentation', 1, 'agent_end', ['reason' => 'failed']));
        $this->assertEquals(new RunPresentationDTO(
            RunStatus::Failed, 1, 7, 6, 3, 1, 'ordinary', 'Partial answer', true, true, 2, [],
        ), $projector->presentation(1, 7));

        // Terminal status does not erase the completed-result accumulator.
        $projector->observe(RunEvent::forAppend('presentation', 1, 'tool_batch_committed'));
        $this->assertEquals(new RunPresentationDTO(
            RunStatus::Failed, 1, 8, 7, 4, 0, null, 'Partial answer', true, true, 2, [],
        ), $projector->presentation(1, 8));
    }

    public function testExcludedRecordsCountWithoutChangingSelectedPresentation(): void
    {
        $projector = new RunPresentationProjector(new ReplayAssistantMessageFactory(), self::getContainer()->get(ToolExecutionEndPayloadCodec::class), 0, 800);
        $projector->observe(RunEvent::forAppend('presentation', 0, 'run_started', ['payload' => ['messages' => [$this->message('user', 'Initial')]]]));
        $projector->observe(RunEvent::forAppend('presentation', 1, 'llm_step_completed', ['assistant_message' => $this->message('assistant', 'Selected')]));
        $projector->observe(RunEvent::forAppend('presentation', 2, 'llm_step_completed', ['assistant_message' => $this->message('assistant', 'DISCARDED_SECRET')]), false);
        $projector->observe(RunEvent::forAppend('presentation', 1, 'agent_end', ['reason' => 'cancelled']));
        $this->assertEquals(new RunPresentationDTO(
            RunStatus::Cancelled, 1, 4, 4, 2, 0, null, 'Selected', true, true, 2, [],
        ), $projector->presentation(1, 4));
    }

    public function testAnchorMappingCanRestorePreviouslySuppressedSeedingCommands(): void
    {
        $events = [
            new RunEvent('presentation', 1, 0, 'run_started', ['payload' => ['messages' => [$this->message('user', 'Initial')]]]),
            new RunEvent('presentation', 2, 1, 'turn_advanced', ['turn_no' => 1]),
            new RunEvent('presentation', 3, 1, 'llm_step_completed', ['assistant_message' => $this->message('assistant', 'Answer')]),
            new RunEvent('presentation', 4, 1, 'agent_end', ['reason' => 'completed']),
            new RunEvent('presentation', 5, 1, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $this->message('user', 'Seed')]),
            new RunEvent('presentation', 6, 1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']),
        ];
        $plan = HistoryReplayPlan::build($events);
        $this->assertFalse($plan->includes($events[4]));
        $projector = new RunPresentationProjector(new ReplayAssistantMessageFactory(), self::getContainer()->get(ToolExecutionEndPayloadCodec::class), 0, 800);
        foreach ($events as $event) {
            $projector->observe($event, $plan->includes($event));
        }
        $this->assertEquals(new RunPresentationDTO(
            RunStatus::Completed, 1, 6, 6, 2, 0, null, 'Answer', true, true, 2, [],
        ), $projector->presentation($plan->positionTurnNo, 6));

        // Mapping the pending seed to a new retained anchor changes inclusion
        // retroactively, even though this suffix contains no navigation event.
        $events[] = new RunEvent('presentation', 7, 2, 'turn_advanced', ['turn_no' => 2]);
        $plan = HistoryReplayPlan::build($events);
        $this->assertTrue($plan->includes($events[4]));
        $projector = new RunPresentationProjector(new ReplayAssistantMessageFactory(), self::getContainer()->get(ToolExecutionEndPayloadCodec::class), 0, 800);
        foreach ($events as $event) {
            $projector->observe($event, $plan->includes($event));
        }
        $this->assertEquals(new RunPresentationDTO(
            RunStatus::Running, 2, 7, 7, 3, 0, null, 'Answer', true, true, 3, [],
        ), $projector->presentation($plan->positionTurnNo, 7));
    }

    public function testPresentationStillValidatesTheCompleteToolResult(): void
    {
        $projector = new RunPresentationProjector(new ReplayAssistantMessageFactory(), self::getContainer()->get(ToolExecutionEndPayloadCodec::class), 0, 800);
        $this->expectException(\UnexpectedValueException::class);
        $projector->observe(new RunEvent('presentation', 1, 0, 'tool_execution_end', ['tool_result' => ['tool_call_id' => 'ordinary']]));
    }

    /** @return array{role: string, content: list<array{type: string, text: string}>} */
    private function message(string $role, string $text): array
    {
        return ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
    }
}
