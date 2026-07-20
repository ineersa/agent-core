<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Replay;

use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis: replaying canonical waiting_human reconstructs a typed pending
 * model-turn request; replaying the matching human_response clears it.
 * Without durable pending-request state, answer validation and future tool-call
 * continuation cannot be correct after process restart/resume.
 */
final class PendingHumanInputRequestReplayTest extends TestCase
{
    public function testWaitingHumanReplayRetainsTypedActiveModelTurnRequest(): void
    {
        $runId = 'run-pending-hitl-replay';
        $reducer = new RunStateReducer();

        $events = [
            new RunEvent(
                runId: $runId,
                seq: 1,
                turnNo: 0,
                type: RunEventTypeEnum::RunStarted->value,
                payload: [
                    'step_id' => 'start',
                    'messages' => [],
                ],
            ),
            new RunEvent(
                runId: $runId,
                seq: 2,
                turnNo: 1,
                type: RunEventTypeEnum::TurnAdvanced->value,
                payload: [
                    'turn_no' => 1,
                    'step_id' => 'turn-1',
                ],
            ),
            new RunEvent(
                runId: $runId,
                seq: 3,
                turnNo: 1,
                type: RunEventTypeEnum::WaitingHuman->value,
                payload: [
                    'kind' => 'interrupt',
                    'question_id' => 'ah_q1',
                    'prompt' => 'Approve the change?',
                    'schema' => ['type' => 'boolean'],
                    'tool_call_id' => 'tc-ask-1',
                    'tool_name' => 'ask_human',
                    'ui_kind' => 'confirm',
                ],
            ),
        ];

        $state = $reducer->replay(RunState::queued($runId), $events);

        $this->assertSame(RunStatus::WaitingHuman, $state->status);
        $this->assertCount(1, $state->pendingHumanInputRequests);
        $request = $state->pendingHumanInputRequests[0];
        $this->assertSame('ah_q1', $request->questionId);
        $this->assertSame('Approve the change?', $request->prompt);
        $this->assertSame(['type' => 'boolean'], $request->schema);
        $this->assertSame(HumanInputContinuationKindEnum::ModelTurn, $request->continuationKind);
        $this->assertSame('tc-ask-1', $request->toolCallId);
        $this->assertSame('ask_human', $request->toolName);
        $this->assertSame('confirm', $request->displayPayload['ui_kind'] ?? null);
    }

    public function testHumanResponseReplayClearsMatchingPendingRequest(): void
    {
        $runId = 'run-pending-hitl-clear';
        $reducer = new RunStateReducer();

        $events = [
            new RunEvent(
                runId: $runId,
                seq: 1,
                turnNo: 0,
                type: RunEventTypeEnum::RunStarted->value,
                payload: [
                    'step_id' => 'start',
                    'messages' => [],
                ],
            ),
            new RunEvent(
                runId: $runId,
                seq: 2,
                turnNo: 1,
                type: RunEventTypeEnum::WaitingHuman->value,
                payload: [
                    'kind' => 'interrupt',
                    'question_id' => 'ah_clear',
                    'prompt' => 'Continue?',
                    'schema' => ['type' => 'string'],
                    'tool_call_id' => 'tc-clear',
                    'tool_name' => 'ask_human',
                ],
            ),
            new RunEvent(
                runId: $runId,
                seq: 3,
                turnNo: 1,
                type: RunEventTypeEnum::AgentCommandApplied->value,
                payload: [
                    'kind' => 'human_response',
                    'idempotency_key' => 'human-1',
                    'question_id' => 'ah_clear',
                    'answer' => 'yes',
                    'message' => [
                        'role' => 'user',
                        'content' => [[
                            'type' => 'text',
                            'text' => '{"question_id":"ah_clear","answer":"yes"}',
                        ]],
                        'metadata' => ['question_id' => 'ah_clear'],
                    ],
                ],
            ),
        ];

        $state = $reducer->replay(RunState::queued($runId), $events);

        $this->assertSame(RunStatus::Running, $state->status);
        $this->assertSame([], $state->pendingHumanInputRequests);
        $this->assertCount(1, $state->messages);
        $this->assertSame('user', $state->messages[0]->role);
    }
}
