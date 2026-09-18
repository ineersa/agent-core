<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Replay;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle: pending human questions must not survive follow_up / cancel / agent_end,
 * while same-turn FIFO multi-question queues remain valid and context_refreshed is a no-op.
 */
final class RunStateReducerPendingHumanLifecycleTest extends TestCase
{
    public function testSessionFourShapedFollowUpClearsOrphanBeforeLaterResponse(): void
    {
        $runId = 'run-session4-orphan';
        $state = $this->reducer()->replay(RunState::queued($runId), [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, ['step_id' => 'start', 'messages' => []]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::WaitingHuman->value, [
                'kind' => 'interrupt',
                'question_id' => 'ah_21eac',
                'prompt' => 'Question A?',
            ]),
            new RunEvent($runId, 3, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'follow_up',
                'idempotency_key' => 'fu-1',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'continue']]],
            ]),
            new RunEvent($runId, 4, 2, RunEventTypeEnum::WaitingHuman->value, [
                'kind' => 'interrupt',
                'question_id' => 'ah_4bb5',
                'prompt' => 'Question B?',
            ]),
            new RunEvent($runId, 5, 2, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'human_response',
                'idempotency_key' => 'hr-b',
                'question_id' => 'ah_4bb5',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Cancelled by user']]],
            ]),
        ]);

        $this->assertSame(RunStatus::Running, $state->status);
        $this->assertSame([], $state->pendingHumanInputRequests);
    }

    public function testSameTurnMultiQuestionFifoStillRequiresHeadMatch(): void
    {
        $runId = 'run-multi-hitl';
        $reducer = $this->reducer();
        $events = [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, ['step_id' => 'start', 'messages' => []]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::WaitingHuman->value, [
                'kind' => 'interrupt',
                'question_id' => 'ah_a',
                'prompt' => 'A?',
            ]),
            new RunEvent($runId, 3, 1, RunEventTypeEnum::WaitingHuman->value, [
                'kind' => 'interrupt',
                'question_id' => 'ah_b',
                'prompt' => 'B?',
            ]),
        ];

        $waiting = $reducer->replay(RunState::queued($runId), $events);
        $this->assertSame(RunStatus::WaitingHuman, $waiting->status);
        $this->assertSame(['ah_a', 'ah_b'], array_map(
            static fn ($request): string => $request->questionId,
            $waiting->pendingHumanInputRequests,
        ));

        $answeredA = $reducer->replay($waiting, [
            new RunEvent($runId, 4, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'human_response',
                'idempotency_key' => 'hr-a',
                'question_id' => 'ah_a',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'answer-a']]],
            ]),
        ]);
        $this->assertSame(['ah_b'], array_map(
            static fn ($request): string => $request->questionId,
            $answeredA->pendingHumanInputRequests,
        ));
        $this->assertSame(RunStatus::WaitingHuman, $answeredA->status);

        $answeredB = $reducer->replay($answeredA, [
            new RunEvent($runId, 5, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'human_response',
                'idempotency_key' => 'hr-b',
                'question_id' => 'ah_b',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'answer-b']]],
            ]),
        ]);
        $this->assertSame([], $answeredB->pendingHumanInputRequests);
        $this->assertSame(RunStatus::Running, $answeredB->status);
    }

    public function testCancelAndAgentEndClearPendingHumanRequests(): void
    {
        $runId = 'run-cancel-pending';
        $state = $this->reducer()->replay(RunState::queued($runId), [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, ['step_id' => 'start', 'messages' => []]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::WaitingHuman->value, [
                'kind' => 'interrupt',
                'question_id' => 'ah_open',
                'prompt' => 'Open?',
            ]),
            new RunEvent($runId, 3, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'cancel',
                'idempotency_key' => 'cancel-1',
                'reason' => 'Esc',
            ]),
            new RunEvent($runId, 4, 1, RunEventTypeEnum::AgentEnd->value, [
                'reason' => 'cancelled',
            ]),
        ]);

        $this->assertSame(RunStatus::Cancelled, $state->status);
        $this->assertSame([], $state->pendingHumanInputRequests);
    }

    public function testContextRefreshedDoesNotClearPendingHumanRequests(): void
    {
        $runId = 'run-refresh-keeps-pending';
        $state = $this->reducer()->replay(RunState::queued($runId), [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, ['step_id' => 'start', 'messages' => []]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::WaitingHuman->value, [
                'kind' => 'interrupt',
                'question_id' => 'ah_keep',
                'prompt' => 'Keep?',
            ]),
            new RunEvent($runId, 3, 1, RunEventTypeEnum::ContextRefreshed->value, [
                'messages' => [],
            ]),
        ]);

        $this->assertSame(RunStatus::WaitingHuman, $state->status);
        $this->assertCount(1, $state->pendingHumanInputRequests);
        $this->assertSame('ah_keep', $state->pendingHumanInputRequests[0]->questionId);
    }

    public function testLateHumanResponseDoesNotAppendMessageToHistory(): void
    {
        $runId = 'run-late-no-message';
        $state = $this->reducer()->replay(RunState::queued($runId), [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, ['step_id' => 'start', 'messages' => []]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::WaitingHuman->value, [
                'kind' => 'interrupt',
                'question_id' => 'ah_open',
                'prompt' => 'Open?',
            ]),
            new RunEvent($runId, 3, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'cancel',
                'idempotency_key' => 'cancel-1',
            ]),
            new RunEvent($runId, 4, 1, RunEventTypeEnum::AgentEnd->value, [
                'reason' => 'cancelled',
            ]),
            new RunEvent($runId, 5, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'human_response',
                'idempotency_key' => 'late-hr',
                'question_id' => 'ah_open',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'stale cancelled answer']]],
            ]),
        ]);

        $this->assertSame(RunStatus::Cancelled, $state->status);
        $this->assertSame([], $state->pendingHumanInputRequests);
        $this->assertSame([], $state->messages);
    }

    public function testMissingQuestionIdStillFailsClosed(): void
    {
        $runId = 'run-missing-qid';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing non-empty question_id');

        $this->reducer()->replay(RunState::queued($runId), [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, ['step_id' => 'start', 'messages' => []]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::WaitingHuman->value, [
                'kind' => 'interrupt',
                'question_id' => 'ah_open',
                'prompt' => 'Open?',
            ]),
            new RunEvent($runId, 3, 1, RunEventTypeEnum::AgentCommandApplied->value, [
                'kind' => 'human_response',
                'idempotency_key' => 'bad-hr',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'no id']]],
            ]),
        ]);
    }

    private function reducer(): RunStateReducer
    {
        return new RunStateReducer(
            AttributeSerializerValidatorTestFactory::denormalizer(),
            new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()),
        );
    }
}
