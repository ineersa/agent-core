<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;

/**
 * Slice A contracts: replay retains typed request; mismatched answer rejects;
 * matching model-turn answer clears request and schedules AdvanceRun.
 */
final class PendingHumanInputAnswerValidationTest extends TestCase
{
    public function testWaitingHumanReplayRetainsTypedActiveModelTurnRequest(): void
    {
        $payload = [
            'kind' => 'interrupt',
            'question_id' => 'ah_q1',
            'prompt' => 'Approve the change?',
            'schema' => ['type' => 'boolean'],
            'tool_call_id' => 'tc-ask-1',
            'tool_name' => 'ask_human',
            'ui_kind' => 'confirm',
        ];
        $runId = 'run-pending-hitl-replay';
        $state = (new RunStateReducer(AttributeSerializerValidatorTestFactory::serializer(), new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())))->replay(RunState::queued($runId), [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, ['step_id' => 'start', 'messages' => []]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::WaitingHuman->value, $payload),
        ]);

        $this->assertSame(RunStatus::WaitingHuman, $state->status);
        $this->assertCount(1, $state->pendingHumanInputRequests);
        $request = $state->pendingHumanInputRequests[0];
        $this->assertSame('ah_q1', $request->questionId);
        $this->assertSame(HumanInputContinuationKindEnum::ModelTurn, $request->continuationKind);
        $this->assertSame($payload, $request->payload);
    }

    public function testUnknownQuestionIdIsNoOpWhileWaiting(): void
    {
        $result = $this->applyHandler()->handle(
            $this->humanResponse('run-hitl-mismatch', 'ah_stale', 'nope'),
            $this->waitingState('run-hitl-mismatch', 'ah_expected'),
        );

        $this->assertNull($result->nextState);
        $this->assertSame([], $result->events);
        $this->assertSame([], $result->postCommit);
    }

    public function testNonHeadPendingQuestionIdIsRejected(): void
    {
        $state = RunStateBuilder::running('run-hitl-nonhead')
            ->withStatus(RunStatus::WaitingHuman)
            ->withVersion(4)
            ->withTurnNo(1)
            ->withLastSeq(8)
            ->withPendingHumanInputRequests([
                PendingHumanInputRequestDTO::modelTurnFromInterruptPayload([
                    'question_id' => 'ah_head',
                    'prompt' => 'Head?',
                ]),
                PendingHumanInputRequestDTO::modelTurnFromInterruptPayload([
                    'question_id' => 'ah_tail',
                    'prompt' => 'Tail?',
                ]),
            ])
            ->build();

        $result = $this->applyHandler()->handle(
            $this->humanResponse('run-hitl-nonhead', 'ah_tail', 'skip head'),
            $state,
        );

        $this->assertSame(RunStatus::WaitingHuman, $result->nextState?->status);
        $this->assertSame('ah_head', $result->nextState?->pendingHumanInputRequests[0]->questionId);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::AgentCommandRejected->value, $result->events[0]->type);
        $this->assertStringContainsString('question_id', (string) $result->nextState?->errorMessage);
        $this->assertSame([], $result->postCommit);
    }

    public function testMatchingModelTurnAnswerClearsRequestAndSchedulesAdvance(): void
    {
        $bus = new TestMessageBus();
        $result = $this->applyHandler($bus)->handle(
            $this->humanResponse('run-hitl-ok', 'ah_ok', 'yes proceed'),
            $this->waitingState('run-hitl-ok', 'ah_ok'),
        );

        $this->assertSame(RunStatus::Running, $result->nextState?->status);
        $this->assertSame([], $result->nextState?->pendingHumanInputRequests);
        $this->assertSame('user', $result->nextState?->messages[0]->role);
        $this->assertStringContainsString('yes proceed', (string) ($result->nextState?->messages[0]->content[0]['text'] ?? ''));
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::AgentCommandApplied->value, $result->events[0]->type);
        $this->assertSame('ah_ok', $result->events[0]->payload['question_id'] ?? null);
        foreach ($result->postCommit as $callback) {
            $callback();
        }
        $this->assertInstanceOf(AdvanceRun::class, $bus->messages[0] ?? null);
        $this->assertSame('run-hitl-ok', $bus->messages[0]->runId());
    }

    public function testLateAnswerAfterCancelIsNoOp(): void
    {
        $result = $this->applyHandler()->handle(
            $this->humanResponse('run-hitl-late', 'ah_gone', 'too late'),
            RunStateBuilder::running('run-hitl-late')
                ->withStatus(RunStatus::Cancelled)
                ->withVersion(4)
                ->withTurnNo(1)
                ->withLastSeq(8)
                ->withPendingHumanInputRequests([])
                ->build(),
        );

        $this->assertNull($result->nextState);
        $this->assertSame([], $result->events);
        $this->assertSame([], $result->postCommit);
    }

    public function testCancelWhileWaitingClearsPendingHumanRequests(): void
    {
        $result = $this->applyHandler()->handle(
            new ApplyCommand(
                runId: 'run-hitl-cancel-clear',
                turnNo: 1,
                stepId: 'cancel-step',
                attempt: 1,
                idempotencyKey: 'cancel-clear-1',
                kind: CoreCommandKind::Cancel,
                payload: ['reason' => 'Esc'],
            ),
            $this->waitingState('run-hitl-cancel-clear', 'ah_open'),
        );

        $this->assertSame(RunStatus::Cancelled, $result->nextState?->status);
        $this->assertSame([], $result->nextState?->pendingHumanInputRequests);
        $this->assertContains(RunEventTypeEnum::AgentEnd->value, array_map(
            static fn (RunEvent $event): string => $event->type,
            $result->events,
        ));
    }

    public function testFollowUpWhileWaitingClearsPendingOnAdvanceBoundary(): void
    {
        $store = new InMemoryCommandStore();
        $router = new CommandRouter([]);
        $mailbox = new CommandMailboxPolicy($store, $router);
        $handler = new \Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler(
            commandMailboxPolicy: $mailbox,
            eventFactory: new EventFactory(),
        );

        $store->enqueue(new \Ineersa\AgentCore\Domain\Command\PendingCommand(
            runId: 'run-followup-clear',
            kind: CoreCommandKind::FollowUp,
            idempotencyKey: 'fu-clear-1',
            payload: ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'go on']]]],
            options: new \Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions(safe: false),
        ));

        $state = $this->waitingState('run-followup-clear', 'ah_orphan');
        $result = $handler->handle(
            new AdvanceRun(
                runId: 'run-followup-clear',
                turnNo: 1,
                stepId: 'advance-fu',
                attempt: 1,
                idempotencyKey: 'advance-fu-1',
            ),
            $state,
        );

        $this->assertSame(RunStatus::Running, $result->nextState?->status);
        $this->assertSame([], $result->nextState?->pendingHumanInputRequests);
    }

    public function testMissingQuestionIdWhileWaitingIsRejected(): void
    {
        $result = $this->applyHandler()->handle(
            new ApplyCommand(
                runId: 'run-hitl-missing-qid',
                turnNo: 1,
                stepId: 'human-step',
                attempt: 1,
                idempotencyKey: 'human-missing',
                kind: CoreCommandKind::HumanResponse,
                payload: ['answer' => 'nope'],
            ),
            $this->waitingState('run-hitl-missing-qid', 'ah_expected'),
        );

        $this->assertSame(RunStatus::WaitingHuman, $result->nextState?->status);
        $this->assertSame('ah_expected', $result->nextState?->pendingHumanInputRequests[0]->questionId);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::AgentCommandRejected->value, $result->events[0]->type);
        $this->assertStringContainsString('question_id', (string) $result->nextState?->errorMessage);
    }

    public function testLateAnswerLogsStructuredNoOp(): void
    {
        $logger = new TestLogger();
        $result = $this->applyHandler(logger: $logger)->handle(
            $this->humanResponse('run-hitl-late-log', 'ah_gone', 'too late'),
            RunStateBuilder::running('run-hitl-late-log')
                ->withStatus(RunStatus::Cancelled)
                ->withVersion(4)
                ->withTurnNo(1)
                ->withLastSeq(8)
                ->withPendingHumanInputRequests([])
                ->build(),
        );

        $this->assertNull($result->nextState);
        $this->assertSame([], $result->events);
        $late = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'Late human_response ignored' === $record['message'],
        ));
        $this->assertCount(1, $late);
        $this->assertSame('human_response.late_noop', $late[0]['context']['event_type'] ?? null);
        $this->assertSame('run_not_waiting_human', $late[0]['context']['reason'] ?? null);
        $this->assertSame('ah_gone', $late[0]['context']['question_id'] ?? null);
    }

    public function testCancelDeferredToolContinuationTerminalizesNotCancelling(): void
    {
        $collector = new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector();
        $collector->registerExpectedBatch('run-tool-cancel', 1, 'step-t', [
            new \Ineersa\AgentCore\Domain\Message\ExecuteToolCall('run-tool-cancel', 1, 'step-t', 1, 'idemp-t', 'call-t', 'bash', ['command' => 'ls'], 0),
        ]);
        $collector->admitHumanInputSuspension('run-tool-cancel', 1, 'step-t', 'call-t', 'q-t');

        $store = new InMemoryCommandStore();
        $router = new CommandRouter([]);
        $handler = new ApplyCommandHandler(
            commandStore: $store,
            commandRouter: $router,
            commandMailboxPolicy: new CommandMailboxPolicy($store, $router),
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            toolBatchCollector: $collector,
            serializer: AttributeSerializerValidatorTestFactory::serializer(),
        );

        $assistant = new \Ineersa\AgentCore\Domain\Message\AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'approve']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'call-t', 'name' => 'bash', 'arguments' => ['command' => 'ls'], 'order_index' => 0],
                ],
            ],
        );

        $state = RunStateBuilder::running('run-tool-cancel')
            ->withStatus(RunStatus::WaitingHuman)
            ->withTurnNo(1)
            ->withLastSeq(5)
            ->withActiveStepId('step-t')
            ->withPendingToolCalls(['call-t' => false])
            ->withPendingHumanInputRequests([
                PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q-t', 'prompt' => 'Allow?'],
                    ['run_id' => 'run-tool-cancel', 'turn_no' => 1, 'step_id' => 'step-t', 'tool_call_id' => 'call-t'],
                ),
            ])
            ->withMessages([$assistant])
            ->build();

        $result = $handler->handle(new ApplyCommand(
            runId: 'run-tool-cancel',
            turnNo: 1,
            stepId: 'cancel-t',
            attempt: 1,
            idempotencyKey: 'cancel-t-1',
            kind: CoreCommandKind::Cancel,
            payload: ['reason' => 'attach'],
        ), $state);

        $this->assertSame(RunStatus::Cancelled, $result->nextState?->status);
        $this->assertSame([], $result->nextState?->pendingToolCalls);
        $this->assertSame([], $result->nextState?->pendingHumanInputRequests);
        $this->assertContains(RunEventTypeEnum::ToolExecutionEnd->value, array_map(
            static fn (RunEvent $event): string => $event->type,
            $result->events,
        ));
        $this->assertContains(RunEventTypeEnum::AgentEnd->value, array_map(
            static fn (RunEvent $event): string => $event->type,
            $result->events,
        ));
    }

    private function waitingState(string $runId, string $questionId): RunState
    {
        return RunStateBuilder::running($runId)
            ->withStatus(RunStatus::WaitingHuman)
            ->withVersion(4)
            ->withTurnNo(1)
            ->withLastSeq(8)
            ->withPendingHumanInputRequests([
                PendingHumanInputRequestDTO::modelTurnFromInterruptPayload([
                    'question_id' => $questionId,
                    'prompt' => 'Continue?',
                    'schema' => ['type' => 'string'],
                ]),
            ])
            ->build();
    }

    private function humanResponse(string $runId, string $questionId, string $answer): ApplyCommand
    {
        return new ApplyCommand(
            runId: $runId,
            turnNo: 1,
            stepId: 'human-step',
            attempt: 1,
            idempotencyKey: 'human-'.$questionId,
            kind: CoreCommandKind::HumanResponse,
            payload: ['question_id' => $questionId, 'answer' => $answer],
        );
    }

    private function applyHandler(?TestMessageBus $bus = null, ?TestLogger $logger = null): ApplyCommandHandler
    {
        $store = new InMemoryCommandStore();
        $router = new CommandRouter([]);

        return new ApplyCommandHandler(
            commandStore: $store,
            commandRouter: $router,
            commandMailboxPolicy: new CommandMailboxPolicy($store, $router),
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: $bus,
            logger: $logger,
            serializer: AttributeSerializerValidatorTestFactory::serializer(),
        );
    }
}
