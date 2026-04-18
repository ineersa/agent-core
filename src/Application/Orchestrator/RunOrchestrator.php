<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\OutboxProjector;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Reducer\RunReducer;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\BoundaryHookName;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class RunOrchestrator
{
    private const string ScopeStartRun = 'command.start';
    private const string ScopeApplyCommand = 'command.apply';
    private const string ScopeAdvanceRun = 'command.advance';
    private const string ScopeLlmResult = 'result.llm';
    private const string ScopeToolResult = 'result.tool';

    public function __construct(
        private RunStoreInterface $runStore,
        private EventStoreInterface $eventStore,
        private CommandStoreInterface $commandStore,
        private RunReducer $reducer,
        private StepDispatcher $stepDispatcher,
        private CommandRouter $commandRouter,
        private OutboxProjector $outboxProjector,
        private ReplayService $replayService,
        private MessageIdempotencyService $idempotency,
        private RunLockManager $runLockManager,
        private ToolBatchCollector $toolBatchCollector,
        private ?HookDispatcher $hookDispatcher = null,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onStartRun(StartRun $message): void
    {
        $runId = $message->runId();

        $this->runLockManager->synchronized($runId, function () use ($message, $runId): void {
            if ($this->idempotency->wasHandled(self::ScopeStartRun, $runId, $message->idempotencyKey())) {
                return;
            }

            $state = $this->runStore->get($runId) ?? RunState::queued($runId);
            $result = $this->reducer->reduce($state, $message);

            if ($result->state->version === $state->version) {
                $this->idempotency->markHandled(self::ScopeStartRun, $runId, $message->idempotencyKey());

                return;
            }

            $event = $this->event(
                runId: $runId,
                seq: $result->state->lastSeq,
                turnNo: $result->state->turnNo,
                type: 'run_started',
                payload: [
                    'step_id' => $message->stepId(),
                    'payload' => $message->payload,
                ],
            );

            if (!$this->commit($state, $result->state, [$event], $result->effects)) {
                return;
            }

            $this->idempotency->markHandled(self::ScopeStartRun, $runId, $message->idempotencyKey());
        });
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onApplyCommand(ApplyCommand $message): void
    {
        $runId = $message->runId();

        $this->runLockManager->synchronized($runId, function () use ($message, $runId): void {
            if ($this->idempotency->wasHandled(self::ScopeApplyCommand, $runId, $message->idempotencyKey())) {
                return;
            }

            $state = $this->runStore->get($runId) ?? RunState::queued($runId);
            $routedCommand = $this->commandRouter->route($message);

            if ($routedCommand->isRejected()) {
                $reason = \is_string($routedCommand->reason) ? $routedCommand->reason : 'Command rejected by router.';
                $this->commandStore->markRejected($runId, $message->idempotencyKey(), $reason);

                $nextState = new RunState(
                    runId: $state->runId,
                    status: $state->status,
                    version: $state->version + 1,
                    turnNo: $state->turnNo,
                    lastSeq: $state->lastSeq + 1,
                    isStreaming: $state->isStreaming,
                    streamingMessage: $state->streamingMessage,
                    pendingToolCalls: $state->pendingToolCalls,
                    errorMessage: $reason,
                    messages: $state->messages,
                    activeStepId: $state->activeStepId,
                );

                $event = $this->event(
                    runId: $runId,
                    seq: $nextState->lastSeq,
                    turnNo: $nextState->turnNo,
                    type: 'agent_command_rejected',
                    payload: [
                        'kind' => $message->kind,
                        'reason' => $reason,
                        'idempotency_key' => $message->idempotencyKey(),
                    ],
                );

                if (!$this->commit($state, $nextState, [$event])) {
                    return;
                }

                $this->idempotency->markHandled(self::ScopeApplyCommand, $runId, $message->idempotencyKey());

                return;
            }

            $this->commandStore->markApplied($runId, $message->idempotencyKey());
            $result = $this->reducer->reduce($state, $message);

            $event = $this->event(
                runId: $runId,
                seq: $result->state->lastSeq,
                turnNo: $result->state->turnNo,
                type: 'agent_command_applied',
                payload: [
                    'kind' => $message->kind,
                    'idempotency_key' => $message->idempotencyKey(),
                    'options' => $message->options,
                ],
            );

            if (!$this->commit($state, $result->state, [$event], $result->effects)) {
                return;
            }

            $this->idempotency->markHandled(self::ScopeApplyCommand, $runId, $message->idempotencyKey());
        });
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onAdvanceRun(AdvanceRun $message): void
    {
        $runId = $message->runId();

        $this->runLockManager->synchronized($runId, function () use ($message, $runId): void {
            if ($this->idempotency->wasHandled(self::ScopeAdvanceRun, $runId, $message->idempotencyKey())) {
                return;
            }

            $state = $this->runStore->get($runId) ?? RunState::queued($runId);
            $result = $this->reducer->reduce($state, $message);

            if ($result->state->version === $state->version) {
                $this->idempotency->markHandled(self::ScopeAdvanceRun, $runId, $message->idempotencyKey());

                return;
            }

            $event = $this->event(
                runId: $runId,
                seq: $result->state->lastSeq,
                turnNo: $result->state->turnNo,
                type: 'turn_advanced',
                payload: [
                    'step_id' => $result->state->activeStepId,
                    'turn_no' => $result->state->turnNo,
                ],
            );

            if (!$this->commit($state, $result->state, [$event], $result->effects)) {
                return;
            }

            $this->idempotency->markHandled(self::ScopeAdvanceRun, $runId, $message->idempotencyKey());
        });
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onLlmStepResult(LlmStepResult $message): void
    {
        $runId = $message->runId();

        $this->runLockManager->synchronized($runId, function () use ($message, $runId): void {
            if ($this->idempotency->wasHandled(self::ScopeLlmResult, $runId, $message->idempotencyKey())) {
                return;
            }

            $state = $this->runStore->get($runId) ?? RunState::queued($runId);

            if ($this->isStaleResult($state, $message->turnNo(), $message->stepId())) {
                $nextState = $this->incrementStateVersion($state, eventCount: 1);
                $event = $this->event(
                    runId: $runId,
                    seq: $nextState->lastSeq,
                    turnNo: $state->turnNo,
                    type: 'stale_result_ignored',
                    payload: [
                        'result' => 'llm_step_result',
                        'step_id' => $message->stepId(),
                        'turn_no' => $message->turnNo(),
                    ],
                );

                if (!$this->commit($state, $nextState, [$event])) {
                    return;
                }

                $this->idempotency->markHandled(self::ScopeLlmResult, $runId, $message->idempotencyKey());

                return;
            }

            if ('aborted' === $message->stopReason || RunStatus::Cancelling === $state->status) {
                $messages = $state->messages;
                if (null !== $message->assistantMessage) {
                    $messages[] = $this->assistantMessage($message->assistantMessage);
                }

                $nextState = new RunState(
                    runId: $state->runId,
                    status: RunStatus::Cancelled,
                    version: $state->version + 1,
                    turnNo: $state->turnNo,
                    lastSeq: $state->lastSeq + 1,
                    isStreaming: false,
                    streamingMessage: null,
                    pendingToolCalls: [],
                    errorMessage: $state->errorMessage ?? 'Run cancelled during LLM streaming.',
                    messages: $messages,
                    activeStepId: $state->activeStepId,
                );

                $event = $this->event(
                    runId: $runId,
                    seq: $nextState->lastSeq,
                    turnNo: $nextState->turnNo,
                    type: 'llm_step_aborted',
                    payload: [
                        'step_id' => $message->stepId(),
                        'stop_reason' => $message->stopReason ?? 'aborted',
                        'usage' => $message->usage,
                    ],
                );

                if (!$this->commit($state, $nextState, [$event])) {
                    return;
                }

                $this->idempotency->markHandled(self::ScopeLlmResult, $runId, $message->idempotencyKey());

                return;
            }

            if (null !== $message->error) {
                $errorMessage = \is_string($message->error['message'] ?? null)
                    ? $message->error['message']
                    : 'LLM worker failed.';

                $nextState = new RunState(
                    runId: $state->runId,
                    status: RunStatus::Failed,
                    version: $state->version + 1,
                    turnNo: $state->turnNo,
                    lastSeq: $state->lastSeq + 1,
                    isStreaming: false,
                    streamingMessage: null,
                    pendingToolCalls: [],
                    errorMessage: $errorMessage,
                    messages: $state->messages,
                    activeStepId: $state->activeStepId,
                );

                $event = $this->event(
                    runId: $runId,
                    seq: $nextState->lastSeq,
                    turnNo: $nextState->turnNo,
                    type: 'llm_step_failed',
                    payload: [
                        'error' => $message->error,
                        'step_id' => $message->stepId(),
                    ],
                );

                if (!$this->commit($state, $nextState, [$event])) {
                    return;
                }

                $this->idempotency->markHandled(self::ScopeLlmResult, $runId, $message->idempotencyKey());

                return;
            }

            $assistantMessage = $message->assistantMessage ?? [];
            $toolCalls = $this->extractToolCalls($assistantMessage);

            $messages = $state->messages;
            $messages[] = $this->assistantMessage($assistantMessage);

            $pendingToolCalls = [];
            foreach ($toolCalls as $toolCall) {
                $pendingToolCalls[$toolCall['id']] = false;
            }

            $nextState = new RunState(
                runId: $state->runId,
                status: RunStatus::Running,
                version: $state->version + 1,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq + 1,
                isStreaming: false,
                streamingMessage: null,
                pendingToolCalls: $pendingToolCalls,
                errorMessage: null,
                messages: $messages,
                activeStepId: $state->activeStepId,
            );

            $event = $this->event(
                runId: $runId,
                seq: $nextState->lastSeq,
                turnNo: $nextState->turnNo,
                type: 'llm_step_completed',
                payload: [
                    'step_id' => $message->stepId(),
                    'stop_reason' => $message->stopReason,
                    'usage' => $message->usage,
                    'tool_calls_count' => \count($toolCalls),
                ],
            );

            $effects = [];
            if ([] !== $toolCalls) {
                foreach ($toolCalls as $toolCall) {
                    $effects[] = new ExecuteToolCall(
                        runId: $runId,
                        turnNo: $state->turnNo,
                        stepId: $message->stepId(),
                        attempt: 1,
                        idempotencyKey: hash('sha256', \sprintf('%s|%s|%s', $runId, $message->stepId(), $toolCall['id'])),
                        toolCallId: $toolCall['id'],
                        toolName: $toolCall['name'],
                        args: $toolCall['args'],
                        orderIndex: $toolCall['order_index'],
                        toolIdempotencyKey: $toolCall['tool_idempotency_key'],
                    );
                }
            }

            if (!$this->commit($state, $nextState, [$event])) {
                return;
            }

            if ([] !== $toolCalls) {
                $this->toolBatchCollector->registerExpectedBatch($runId, $state->turnNo, $message->stepId(), $toolCalls);
                $this->stepDispatcher->dispatchEffects($effects);
            }

            $this->idempotency->markHandled(self::ScopeLlmResult, $runId, $message->idempotencyKey());
        });
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onToolCallResult(ToolCallResult $message): void
    {
        $runId = $message->runId();

        $this->runLockManager->synchronized($runId, function () use ($message, $runId): void {
            if ($this->idempotency->wasHandled(self::ScopeToolResult, $runId, $message->idempotencyKey())) {
                return;
            }

            $state = $this->runStore->get($runId) ?? RunState::queued($runId);

            if ($this->isStaleResult($state, $message->turnNo(), $message->stepId())) {
                $nextState = $this->incrementStateVersion($state, eventCount: 1);
                $event = $this->event(
                    runId: $runId,
                    seq: $nextState->lastSeq,
                    turnNo: $state->turnNo,
                    type: 'stale_result_ignored',
                    payload: [
                        'result' => 'tool_call_result',
                        'tool_call_id' => $message->toolCallId,
                        'step_id' => $message->stepId(),
                        'turn_no' => $message->turnNo(),
                    ],
                );

                if (!$this->commit($state, $nextState, [$event])) {
                    return;
                }

                $this->idempotency->markHandled(self::ScopeToolResult, $runId, $message->idempotencyKey());

                return;
            }

            $outcome = $this->toolBatchCollector->collect($message);
            if ($outcome->duplicate) {
                $this->idempotency->markHandled(self::ScopeToolResult, $runId, $message->idempotencyKey());

                return;
            }

            if (!$outcome->accepted) {
                $nextState = $this->incrementStateVersion($state, eventCount: 1);
                $event = $this->event(
                    runId: $runId,
                    seq: $nextState->lastSeq,
                    turnNo: $state->turnNo,
                    type: 'stale_result_ignored',
                    payload: [
                        'result' => 'tool_call_result',
                        'tool_call_id' => $message->toolCallId,
                        'reason' => 'untracked_tool_call',
                    ],
                );

                if (!$this->commit($state, $nextState, [$event])) {
                    return;
                }

                $this->idempotency->markHandled(self::ScopeToolResult, $runId, $message->idempotencyKey());

                return;
            }

            $events = [
                $this->event(
                    runId: $runId,
                    seq: $state->lastSeq + 1,
                    turnNo: $state->turnNo,
                    type: 'tool_call_result_received',
                    payload: [
                        'tool_call_id' => $message->toolCallId,
                        'order_index' => $message->orderIndex,
                        'is_error' => $message->isError,
                    ],
                ),
            ];

            $pendingToolCalls = $state->pendingToolCalls;
            if (\array_key_exists($message->toolCallId, $pendingToolCalls)) {
                $pendingToolCalls[$message->toolCallId] = true;
            }

            $messages = $state->messages;
            $effects = [];

            if ($outcome->complete) {
                foreach ($outcome->orderedResults as $orderedResult) {
                    $messages[] = $this->toolMessage($orderedResult);
                }

                $events[] = $this->event(
                    runId: $runId,
                    seq: $state->lastSeq + 2,
                    turnNo: $state->turnNo,
                    type: 'tool_batch_committed',
                    payload: [
                        'count' => \count($outcome->orderedResults),
                    ],
                );

                $pendingToolCalls = [];
            }

            $nextState = new RunState(
                runId: $state->runId,
                status: RunStatus::Running,
                version: $state->version + 1,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq + \count($events),
                isStreaming: false,
                streamingMessage: null,
                pendingToolCalls: $pendingToolCalls,
                errorMessage: $state->errorMessage,
                messages: $messages,
                activeStepId: $state->activeStepId,
            );

            if (!$this->commit($state, $nextState, $events, $effects)) {
                return;
            }

            $this->idempotency->markHandled(self::ScopeToolResult, $runId, $message->idempotencyKey());
        });
    }

    /**
     * @param list<RunEvent> $events
     * @param list<object>   $effects
     */
    private function commit(RunState $state, RunState $nextState, array $events, array $effects = []): bool
    {
        if (!$this->runStore->compareAndSwap($nextState, $state->version)) {
            return false;
        }

        if ([] !== $events) {
            if (1 === \count($events)) {
                $this->eventStore->append($events[0]);
            } else {
                $this->eventStore->appendMany($events);
            }

            $this->outboxProjector->project($events);
            $this->replayService->rebuildHotPromptState($nextState->runId);
        }

        if ([] !== $effects) {
            $this->stepDispatcher->dispatchEffects($effects);
        }

        $this->hookDispatcher?->dispatch(BoundaryHookName::AFTER_TURN_COMMIT, [
            'run_id' => $nextState->runId,
            'turn_no' => $nextState->turnNo,
            'status' => $nextState->status->value,
            'events' => array_map(
                static fn (RunEvent $event): array => [
                    'seq' => $event->seq,
                    'type' => $event->type,
                ],
                $events,
            ),
            'effects_count' => \count($effects),
        ]);

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(string $runId, int $seq, int $turnNo, string $type, array $payload = []): RunEvent
    {
        return new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
        );
    }

    private function isStaleResult(RunState $state, int $turnNo, string $stepId): bool
    {
        if ($state->turnNo !== $turnNo) {
            return true;
        }

        return null !== $state->activeStepId && $state->activeStepId !== $stepId;
    }

    private function incrementStateVersion(RunState $state, int $eventCount): RunState
    {
        return new RunState(
            runId: $state->runId,
            status: $state->status,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + $eventCount,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
        );
    }

    /**
     * @param array<string, mixed> $assistantMessage
     */
    private function assistantMessage(array $assistantMessage): AgentMessage
    {
        $content = [];
        $rawContent = $assistantMessage['content'] ?? [];

        if (\is_string($rawContent)) {
            $content[] = [
                'type' => 'text',
                'text' => $rawContent,
            ];
        }

        if (\is_array($rawContent)) {
            foreach ($rawContent as $contentPart) {
                if (!\is_array($contentPart)) {
                    continue;
                }

                $content[] = $contentPart;
            }
        }

        $metadata = [];
        if (\is_array($assistantMessage['tool_calls'] ?? null)) {
            $metadata['tool_calls'] = $assistantMessage['tool_calls'];
        }

        return new AgentMessage(
            role: \is_string($assistantMessage['role'] ?? null) ? $assistantMessage['role'] : 'assistant',
            content: $content,
            metadata: $metadata,
        );
    }

    private function toolMessage(ToolCallResult $result): AgentMessage
    {
        $text = json_encode([
            'is_error' => $result->isError,
            'result' => $result->result,
            'error' => $result->error,
        ]);

        if (false === $text) {
            $text = '{}';
        }

        $toolName = \is_string($result->result['tool_name'] ?? null) ? $result->result['tool_name'] : null;

        return new AgentMessage(
            role: 'tool',
            content: [[
                'type' => 'text',
                'text' => $text,
            ]],
            toolCallId: $result->toolCallId,
            toolName: $toolName,
            details: $result->result,
            isError: $result->isError,
            metadata: [
                'order_index' => $result->orderIndex,
            ],
        );
    }

    /**
     * @param array<string, mixed> $assistantMessage
     *
     * @return list<array{
     *   id: string,
     *   name: string,
     *   args: array<string, mixed>,
     *   order_index: int,
     *   tool_idempotency_key: string|null
     * }>
     */
    private function extractToolCalls(array $assistantMessage): array
    {
        $rawToolCalls = $assistantMessage['tool_calls'] ?? null;
        if (!\is_array($rawToolCalls)) {
            return [];
        }

        $toolCalls = [];

        foreach ($rawToolCalls as $index => $rawToolCall) {
            if (!\is_array($rawToolCall)) {
                continue;
            }

            $id = $rawToolCall['id'] ?? null;
            $name = $rawToolCall['name'] ?? null;

            if (!\is_string($id) || !\is_string($name)) {
                continue;
            }

            $toolCalls[] = [
                'id' => $id,
                'name' => $name,
                'args' => \is_array($rawToolCall['arguments'] ?? null) ? $rawToolCall['arguments'] : [],
                'order_index' => \is_int($rawToolCall['order_index'] ?? null) ? $rawToolCall['order_index'] : $index,
                'tool_idempotency_key' => \is_string($rawToolCall['tool_idempotency_key'] ?? null) ? $rawToolCall['tool_idempotency_key'] : null,
            ];
        }

        return $toolCalls;
    }
}
