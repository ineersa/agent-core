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
use Ineersa\AgentCore\Application\Handler\ToolCatalogResolver;
use Ineersa\AgentCore\Application\Handler\ToolExecutionPolicyResolver;
use Ineersa\AgentCore\Application\Reducer\RunReducer;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\BoundaryHookName;
use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class RunOrchestrator
{
    private const string ScopeStartRun = 'command.start';
    private const string ScopeApplyCommand = 'command.apply';
    private const string ScopeAdvanceRun = 'command.advance';
    private const string ScopeLlmResult = 'result.llm';
    private const string ScopeToolResult = 'result.tool';
    private const string SteerDrainOneAtATime = 'one_at_a_time';
    private const string SteerDrainAll = 'all';

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
        private int $maxPendingCommands = 100,
        private string $steerDrainMode = self::SteerDrainOneAtATime,
        private ?MessageBusInterface $commandBus = null,
        private ?HookDispatcher $hookDispatcher = null,
        private ?ToolExecutionPolicyResolver $toolExecutionPolicyResolver = null,
        private ?ToolCatalogResolver $toolCatalogResolver = null,
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

            if ($this->commandStore->has($runId, $message->idempotencyKey())) {
                $this->idempotency->markHandled(self::ScopeApplyCommand, $runId, $message->idempotencyKey());

                return;
            }

            $state = $this->runStore->get($runId) ?? RunState::queued($runId);
            $routedCommand = $this->commandRouter->route($message);

            if ($routedCommand->isRejected()) {
                $reason = \is_string($routedCommand->reason) ? $routedCommand->reason : 'Command rejected by router.';
                $this->rejectCommand($state, $message, $reason);

                return;
            }

            if (CoreCommandKind::Cancel !== $message->kind && $this->commandStore->countPending($runId) >= $this->maxPendingCommands) {
                $this->rejectCommand($state, $message, \sprintf('Pending command mailbox cap (%d) exceeded for run.', $this->maxPendingCommands));

                return;
            }

            if (RunStatus::Cancelled === $state->status) {
                $this->rejectCommand($state, $message, 'Run is already cancelled.');

                return;
            }

            if (RunStatus::Cancelling === $state->status
                && !$this->isCancelSafeExtensionCommand($message->kind, $routedCommand->options)) {
                $this->rejectCommand($state, $message, \sprintf('Command "%s" rejected because cancellation is in progress.', $message->kind));

                return;
            }

            if (CoreCommandKind::Cancel === $message->kind) {
                $this->applyCancelCommand($state, $message, $routedCommand->options);

                return;
            }

            if (CoreCommandKind::Continue === $message->kind) {
                $this->applyContinueCommand($state, $message, $routedCommand->options);

                return;
            }

            if (CoreCommandKind::HumanResponse === $message->kind) {
                $this->applyHumanResponseCommand($state, $message, $routedCommand->options);

                return;
            }

            $pendingCommand = new PendingCommand(
                runId: $runId,
                kind: $message->kind,
                idempotencyKey: $message->idempotencyKey(),
                payload: $message->payload,
                options: $routedCommand->options,
            );

            if (!$this->commandStore->enqueue($pendingCommand)) {
                $this->idempotency->markHandled(self::ScopeApplyCommand, $runId, $message->idempotencyKey());

                return;
            }

            $nextState = $this->copyState($state, [
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + 1,
            ]);

            $queuedEvent = $this->event(
                runId: $runId,
                seq: $nextState->lastSeq,
                turnNo: $nextState->turnNo,
                type: 'agent_command_queued',
                payload: [
                    'kind' => $message->kind,
                    'idempotency_key' => $message->idempotencyKey(),
                    'options' => $routedCommand->options,
                ],
            );

            if (!$this->commit($state, $nextState, [$queuedEvent])) {
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
            [$preparedState, $boundaryEventSpecs] = $this->applyPendingTurnStartCommands($state);

            if (RunStatus::Cancelling === $preparedState->status) {
                $eventSpecs = [
                    ...$boundaryEventSpecs,
                    [
                        'type' => CoreLifecycleEventType::AGENT_END,
                        'payload' => ['reason' => 'cancelled'],
                    ],
                ];

                $events = $this->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $eventSpecs);
                $nextState = $this->copyState($preparedState, [
                    'status' => RunStatus::Cancelled,
                    'version' => $state->version + 1,
                    'lastSeq' => $state->lastSeq + \count($events),
                    'pendingToolCalls' => [],
                    'isStreaming' => false,
                    'streamingMessage' => null,
                    'retryableFailure' => false,
                ]);

                if (!$this->commit($state, $nextState, $events)) {
                    return;
                }

                $this->idempotency->markHandled(self::ScopeAdvanceRun, $runId, $message->idempotencyKey());

                return;
            }

            if (\in_array($preparedState->status, [RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::WaitingHuman], true)) {
                if ([] !== $boundaryEventSpecs) {
                    $events = $this->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $boundaryEventSpecs);
                    $nextState = $this->copyState($preparedState, [
                        'version' => $state->version + 1,
                        'lastSeq' => $state->lastSeq + \count($events),
                    ]);

                    if (!$this->commit($state, $nextState, $events)) {
                        return;
                    }
                }

                $this->idempotency->markHandled(self::ScopeAdvanceRun, $runId, $message->idempotencyKey());

                return;
            }

            $nextTurnNo = $preparedState->turnNo + 1;
            $nextStepId = $message->stepId();

            $effect = new ExecuteLlmStep(
                runId: $runId,
                turnNo: $nextTurnNo,
                stepId: $nextStepId,
                attempt: 1,
                idempotencyKey: hash('sha256', \sprintf('%s|llm|%d|%s', $runId, $nextTurnNo, $nextStepId)),
                contextRef: \sprintf('hot:run:%s', $runId),
                toolsRef: \sprintf('toolset:run:%s:turn:%d', $runId, $nextTurnNo),
            );

            $eventSpecs = [
                ...$boundaryEventSpecs,
                [
                    'type' => 'turn_advanced',
                    'turn_no' => $nextTurnNo,
                    'payload' => [
                        'step_id' => $nextStepId,
                        'turn_no' => $nextTurnNo,
                    ],
                ],
            ];

            $events = $this->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $eventSpecs);

            $nextState = $this->copyState($preparedState, [
                'status' => RunStatus::Running,
                'version' => $state->version + 1,
                'turnNo' => $nextTurnNo,
                'lastSeq' => $state->lastSeq + \count($events),
                'isStreaming' => false,
                'streamingMessage' => null,
                'activeStepId' => $nextStepId,
                'retryableFailure' => false,
            ]);

            if (!$this->commit($state, $nextState, $events, [$effect])) {
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

                $eventSpecs = [
                    [
                        'type' => 'llm_step_aborted',
                        'payload' => [
                            'step_id' => $message->stepId(),
                            'stop_reason' => $message->stopReason ?? 'aborted',
                            'usage' => $message->usage,
                        ],
                    ],
                    [
                        'type' => CoreLifecycleEventType::AGENT_END,
                        'payload' => [
                            'reason' => 'cancelled',
                        ],
                    ],
                ];

                $events = $this->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

                $nextState = $this->copyState($state, [
                    'status' => RunStatus::Cancelled,
                    'version' => $state->version + 1,
                    'lastSeq' => $state->lastSeq + \count($events),
                    'isStreaming' => false,
                    'streamingMessage' => null,
                    'pendingToolCalls' => [],
                    'errorMessage' => $state->errorMessage ?? 'Run cancelled during LLM streaming.',
                    'messages' => $messages,
                    'retryableFailure' => false,
                ]);

                if (!$this->commit($state, $nextState, $events)) {
                    return;
                }

                $this->idempotency->markHandled(self::ScopeLlmResult, $runId, $message->idempotencyKey());

                return;
            }

            if (null !== $message->error) {
                $errorMessage = \is_string($message->error['message'] ?? null)
                    ? $message->error['message']
                    : 'LLM worker failed.';
                $retryable = \is_bool($message->error['retryable'] ?? null)
                    ? $message->error['retryable']
                    : false;

                $nextState = $this->copyState($state, [
                    'status' => RunStatus::Failed,
                    'version' => $state->version + 1,
                    'lastSeq' => $state->lastSeq + 1,
                    'isStreaming' => false,
                    'streamingMessage' => null,
                    'pendingToolCalls' => [],
                    'errorMessage' => $errorMessage,
                    'retryableFailure' => $retryable,
                ]);

                $event = $this->event(
                    runId: $runId,
                    seq: $nextState->lastSeq,
                    turnNo: $nextState->turnNo,
                    type: 'llm_step_failed',
                    payload: [
                        'error' => $message->error,
                        'retryable' => $retryable,
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
            $toolSchemas = $this->resolveToolSchemas($runId, $state->turnNo, $message->stepId());

            $messages = $state->messages;
            $messages[] = $this->assistantMessage($assistantMessage);

            $pendingToolCalls = [];
            foreach ($toolCalls as $toolCall) {
                $pendingToolCalls[$toolCall['id']] = false;
            }

            $effects = [];
            foreach ($toolCalls as $toolCall) {
                $policy = $this->resolveToolPolicy($toolCall['name']);

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
                    mode: $policy['mode']->value,
                    timeoutSeconds: $policy['timeout_seconds'],
                    maxParallelism: $policy['max_parallelism'],
                    assistantMessage: $assistantMessage,
                    argSchema: $toolSchemas[$toolCall['name']] ?? null,
                );
            }

            $eventSpecs = [[
                'type' => 'llm_step_completed',
                'payload' => [
                    'step_id' => $message->stepId(),
                    'stop_reason' => $message->stopReason,
                    'usage' => $message->usage,
                    'tool_calls_count' => \count($toolCalls),
                ],
            ]];

            if ([] === $toolCalls) {
                $stateAfterAssistant = $this->copyState($state, [
                    'messages' => $messages,
                    'pendingToolCalls' => [],
                    'errorMessage' => null,
                    'retryableFailure' => false,
                ]);

                [$stateAfterBoundary, $boundaryEventSpecs, $shouldContinue] = $this->applyPendingStopBoundaryCommands($stateAfterAssistant);

                $eventSpecs = [
                    ...$eventSpecs,
                    ...$boundaryEventSpecs,
                ];

                if (!$shouldContinue) {
                    $eventSpecs[] = [
                        'type' => CoreLifecycleEventType::AGENT_END,
                        'payload' => [
                            'reason' => 'completed',
                        ],
                    ];
                }

                $events = $this->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

                $nextState = $this->copyState($stateAfterBoundary, [
                    'status' => $shouldContinue ? RunStatus::Running : RunStatus::Completed,
                    'version' => $state->version + 1,
                    'lastSeq' => $state->lastSeq + \count($events),
                    'isStreaming' => false,
                    'streamingMessage' => null,
                    'pendingToolCalls' => [],
                    'errorMessage' => null,
                    'retryableFailure' => false,
                ]);

                if (!$this->commit($state, $nextState, $events)) {
                    return;
                }

                if ($shouldContinue) {
                    $this->dispatchAdvance($runId, 'stop-boundary-follow-up');
                }

                $this->idempotency->markHandled(self::ScopeLlmResult, $runId, $message->idempotencyKey());

                return;
            }

            foreach ($effects as $effect) {
                $eventSpecs[] = [
                    'type' => CoreLifecycleEventType::TOOL_EXECUTION_START,
                    'payload' => [
                        'tool_call_id' => $effect->toolCallId,
                        'tool_name' => $effect->toolName,
                        'order_index' => $effect->orderIndex,
                        'mode' => $effect->mode,
                    ],
                ];
            }

            $events = $this->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

            $nextState = $this->copyState($state, [
                'status' => RunStatus::Running,
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
                'isStreaming' => false,
                'streamingMessage' => null,
                'pendingToolCalls' => $pendingToolCalls,
                'errorMessage' => null,
                'messages' => $messages,
                'retryableFailure' => false,
            ]);

            if (!$this->commit($state, $nextState, $events)) {
                return;
            }

            $initialEffects = $this->toolBatchCollector->registerExpectedBatch($runId, $state->turnNo, $message->stepId(), $effects);
            if ([] !== $initialEffects) {
                $this->stepDispatcher->dispatchEffects($initialEffects);
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

            if ($this->isStaleResult($state, $message->turnNo(), $message->stepId())
                || RunStatus::Cancelled === $state->status) {
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
                        'status' => $state->status->value,
                    ],
                );

                if (!$this->commit($state, $nextState, [$event])) {
                    return;
                }

                $this->idempotency->markHandled(self::ScopeToolResult, $runId, $message->idempotencyKey());

                return;
            }

            if (RunStatus::Cancelling === $state->status) {
                $eventSpecs = [
                    [
                        'type' => 'stale_result_ignored',
                        'payload' => [
                            'result' => 'tool_call_result',
                            'tool_call_id' => $message->toolCallId,
                            'step_id' => $message->stepId(),
                            'turn_no' => $message->turnNo(),
                            'status' => $state->status->value,
                        ],
                    ],
                    [
                        'type' => CoreLifecycleEventType::AGENT_END,
                        'payload' => [
                            'reason' => 'cancelled',
                        ],
                    ],
                ];

                $events = $this->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);
                $nextState = $this->copyState($state, [
                    'status' => RunStatus::Cancelled,
                    'version' => $state->version + 1,
                    'lastSeq' => $state->lastSeq + \count($events),
                    'pendingToolCalls' => [],
                    'isStreaming' => false,
                    'streamingMessage' => null,
                    'retryableFailure' => false,
                ]);

                if (!$this->commit($state, $nextState, $events)) {
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

            $eventSpecs = [
                [
                    'type' => 'tool_call_result_received',
                    'payload' => [
                        'tool_call_id' => $message->toolCallId,
                        'order_index' => $message->orderIndex,
                        'is_error' => $message->isError,
                    ],
                ],
                [
                    'type' => CoreLifecycleEventType::TOOL_EXECUTION_END,
                    'payload' => [
                        'tool_call_id' => $message->toolCallId,
                        'order_index' => $message->orderIndex,
                        'is_error' => $message->isError,
                    ],
                ],
            ];

            $pendingToolCalls = $state->pendingToolCalls;
            if (\array_key_exists($message->toolCallId, $pendingToolCalls)) {
                $pendingToolCalls[$message->toolCallId] = true;
            }

            $messages = $state->messages;
            $effects = $outcome->effectsToDispatch;
            $status = RunStatus::Running;

            if ($outcome->complete) {
                $interruptPayload = null;

                foreach ($outcome->orderedResults as $orderedResult) {
                    $messages[] = $this->toolMessage($orderedResult);

                    $eventSpecs[] = [
                        'type' => CoreLifecycleEventType::MESSAGE_START,
                        'payload' => [
                            'message_role' => 'tool',
                            'tool_call_id' => $orderedResult->toolCallId,
                        ],
                    ];

                    $eventSpecs[] = [
                        'type' => CoreLifecycleEventType::MESSAGE_END,
                        'payload' => [
                            'message_role' => 'tool',
                            'tool_call_id' => $orderedResult->toolCallId,
                        ],
                    ];

                    $interruptPayload ??= $this->interruptPayloadFromToolResult($orderedResult);
                }

                $eventSpecs[] = [
                    'type' => 'tool_batch_committed',
                    'payload' => [
                        'count' => \count($outcome->orderedResults),
                    ],
                ];

                $pendingToolCalls = [];

                if (null !== $interruptPayload) {
                    $status = RunStatus::WaitingHuman;
                    $eventSpecs[] = [
                        'type' => 'waiting_human',
                        'payload' => $interruptPayload,
                    ];
                }
            }

            $events = $this->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

            $nextState = $this->copyState($state, [
                'status' => $status,
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
                'isStreaming' => false,
                'streamingMessage' => null,
                'pendingToolCalls' => $pendingToolCalls,
                'messages' => $messages,
                'retryableFailure' => false,
            ]);

            if (!$this->commit($state, $nextState, $events)) {
                return;
            }

            if ([] !== $effects) {
                $this->stepDispatcher->dispatchEffects($effects);
            }

            $this->idempotency->markHandled(self::ScopeToolResult, $runId, $message->idempotencyKey());
        });
    }

    private function rejectCommand(RunState $state, ApplyCommand $message, string $reason): void
    {
        $runId = $message->runId();
        $this->commandStore->markRejected($runId, $message->idempotencyKey(), $reason);

        $nextState = $this->copyState($state, [
            'version' => $state->version + 1,
            'lastSeq' => $state->lastSeq + 1,
            'errorMessage' => $reason,
        ]);

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
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyCancelCommand(RunState $state, ApplyCommand $message, array $options): void
    {
        unset($options);

        $runId = $message->runId();
        $reason = \is_string($message->payload['reason'] ?? null)
            ? $message->payload['reason']
            : 'Run cancelled by command.';

        $this->commandStore->markApplied($runId, $message->idempotencyKey());
        $rejectedContinueCommands = $this->commandStore->rejectPendingByKind(
            $runId,
            CoreCommandKind::Continue,
            'Rejected because cancel command was accepted.',
        );

        $eventSpecs = [[
            'type' => 'agent_command_applied',
            'payload' => [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'options' => [],
            ],
        ]];

        foreach ($rejectedContinueCommands as $rejectedContinueCommand) {
            $eventSpecs[] = [
                'type' => 'agent_command_rejected',
                'payload' => [
                    'kind' => CoreCommandKind::Continue,
                    'idempotency_key' => $rejectedContinueCommand->idempotencyKey,
                    'reason' => 'Rejected because cancel command was accepted.',
                ],
            ];
        }

        $events = $this->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);
        $nextState = $this->copyState($state, [
            'status' => RunStatus::Cancelling,
            'version' => $state->version + 1,
            'lastSeq' => $state->lastSeq + \count($events),
            'errorMessage' => $reason,
            'retryableFailure' => false,
        ]);

        if (!$this->commit($state, $nextState, $events)) {
            return;
        }

        $this->idempotency->markHandled(self::ScopeApplyCommand, $runId, $message->idempotencyKey());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyContinueCommand(RunState $state, ApplyCommand $message, array $options): void
    {
        $reason = $this->continueRejectionReason($state);
        if (null !== $reason) {
            $this->rejectCommand($state, $message, $reason);

            return;
        }

        $runId = $message->runId();
        $this->commandStore->markApplied($runId, $message->idempotencyKey());

        $nextState = $this->copyState($state, [
            'status' => RunStatus::Running,
            'version' => $state->version + 1,
            'lastSeq' => $state->lastSeq + 1,
            'errorMessage' => null,
            'retryableFailure' => false,
        ]);

        $event = $this->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: 'agent_command_applied',
            payload: [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'options' => $options,
            ],
        );

        if (!$this->commit($state, $nextState, [$event])) {
            return;
        }

        $this->dispatchAdvance($runId, 'continue');
        $this->idempotency->markHandled(self::ScopeApplyCommand, $runId, $message->idempotencyKey());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function applyHumanResponseCommand(RunState $state, ApplyCommand $message, array $options): void
    {
        if (RunStatus::WaitingHuman !== $state->status) {
            $this->rejectCommand($state, $message, 'human_response command is only allowed while run is waiting for human input.');

            return;
        }

        $humanResponseMessage = $this->humanResponseMessage($message->payload);
        if (null === $humanResponseMessage) {
            $this->rejectCommand($state, $message, 'Invalid human_response payload: missing answer.');

            return;
        }

        $runId = $message->runId();
        $this->commandStore->markApplied($runId, $message->idempotencyKey());

        $messages = $state->messages;
        $messages[] = $humanResponseMessage;

        $nextState = $this->copyState($state, [
            'status' => RunStatus::Running,
            'version' => $state->version + 1,
            'lastSeq' => $state->lastSeq + 1,
            'messages' => $messages,
            'errorMessage' => null,
            'retryableFailure' => false,
        ]);

        $event = $this->event(
            runId: $runId,
            seq: $nextState->lastSeq,
            turnNo: $nextState->turnNo,
            type: 'agent_command_applied',
            payload: [
                'kind' => $message->kind,
                'idempotency_key' => $message->idempotencyKey(),
                'question_id' => \is_string($message->payload['question_id'] ?? null) ? $message->payload['question_id'] : null,
                'options' => $options,
            ],
        );

        if (!$this->commit($state, $nextState, [$event])) {
            return;
        }

        $this->dispatchAdvance($runId, 'human-response');
        $this->idempotency->markHandled(self::ScopeApplyCommand, $runId, $message->idempotencyKey());
    }

    /**
     * @return array{0: RunState, 1: list<array{type: string, payload: array<string, mixed>}>}
     */
    private function applyPendingTurnStartCommands(RunState $state): array
    {
        $pendingCommands = $this->commandStore->pending($state->runId);
        if ([] === $pendingCommands) {
            return [$state, []];
        }

        $messages = $state->messages;
        $eventSpecs = [];
        $supersededSteerKeys = $this->supersededSteerKeys($pendingCommands);

        foreach ($pendingCommands as $pendingCommand) {
            if (isset($supersededSteerKeys[$pendingCommand->idempotencyKey])) {
                $this->commandStore->markSuperseded($state->runId, $pendingCommand->idempotencyKey, 'Superseded by a newer steer command.');
                $eventSpecs[] = [
                    'type' => 'agent_command_superseded',
                    'payload' => [
                        'kind' => CoreCommandKind::Steer,
                        'idempotency_key' => $pendingCommand->idempotencyKey,
                        'reason' => 'Superseded by a newer steer command.',
                    ],
                ];

                continue;
            }

            if (CoreCommandKind::Steer === $pendingCommand->kind) {
                $messagePayload = $pendingCommand->payload['message'] ?? null;
                if (!\is_array($messagePayload)) {
                    $this->commandStore->markRejected($state->runId, $pendingCommand->idempotencyKey, 'Invalid steer payload: missing message.');
                    $eventSpecs[] = [
                        'type' => 'agent_command_rejected',
                        'payload' => [
                            'kind' => CoreCommandKind::Steer,
                            'idempotency_key' => $pendingCommand->idempotencyKey,
                            'reason' => 'Invalid steer payload: missing message.',
                        ],
                    ];

                    continue;
                }

                $hydratedMessage = $this->hydrateMessage($messagePayload);
                if (null === $hydratedMessage) {
                    $this->commandStore->markRejected($state->runId, $pendingCommand->idempotencyKey, 'Invalid steer payload: malformed message envelope.');
                    $eventSpecs[] = [
                        'type' => 'agent_command_rejected',
                        'payload' => [
                            'kind' => CoreCommandKind::Steer,
                            'idempotency_key' => $pendingCommand->idempotencyKey,
                            'reason' => 'Invalid steer payload: malformed message envelope.',
                        ],
                    ];

                    continue;
                }

                $messages[] = $hydratedMessage;
                $this->commandStore->markApplied($state->runId, $pendingCommand->idempotencyKey);
                $eventSpecs[] = [
                    'type' => 'agent_command_applied',
                    'payload' => [
                        'kind' => CoreCommandKind::Steer,
                        'idempotency_key' => $pendingCommand->idempotencyKey,
                        'options' => $pendingCommand->options,
                    ],
                ];

                continue;
            }

            if (CoreCommandKind::FollowUp === $pendingCommand->kind) {
                continue;
            }

            if (!CoreCommandKind::isCore($pendingCommand->kind)) {
                $eventSpecs = [
                    ...$eventSpecs,
                    ...$this->applyExtensionCommand($state, $pendingCommand),
                ];
            }
        }

        return [$this->copyState($state, ['messages' => $messages]), $eventSpecs];
    }

    /**
     * @return array{0: RunState, 1: list<array{type: string, payload: array<string, mixed>}>, 2: bool}
     */
    private function applyPendingStopBoundaryCommands(RunState $state): array
    {
        $pendingCommands = $this->commandStore->pending($state->runId);
        if ([] === $pendingCommands) {
            return [$state, [], false];
        }

        $messages = $state->messages;
        $eventSpecs = [];
        $shouldContinue = false;
        $supersededSteerKeys = $this->supersededSteerKeys($pendingCommands);

        foreach ($pendingCommands as $pendingCommand) {
            if (isset($supersededSteerKeys[$pendingCommand->idempotencyKey])) {
                $this->commandStore->markSuperseded($state->runId, $pendingCommand->idempotencyKey, 'Superseded by a newer steer command.');
                $eventSpecs[] = [
                    'type' => 'agent_command_superseded',
                    'payload' => [
                        'kind' => CoreCommandKind::Steer,
                        'idempotency_key' => $pendingCommand->idempotencyKey,
                        'reason' => 'Superseded by a newer steer command.',
                    ],
                ];

                continue;
            }

            if (\in_array($pendingCommand->kind, [CoreCommandKind::Steer, CoreCommandKind::FollowUp], true)) {
                $messagePayload = $pendingCommand->payload['message'] ?? null;
                if (!\is_array($messagePayload)) {
                    $this->commandStore->markRejected($state->runId, $pendingCommand->idempotencyKey, 'Invalid command payload: missing message envelope.');
                    $eventSpecs[] = [
                        'type' => 'agent_command_rejected',
                        'payload' => [
                            'kind' => $pendingCommand->kind,
                            'idempotency_key' => $pendingCommand->idempotencyKey,
                            'reason' => 'Invalid command payload: missing message envelope.',
                        ],
                    ];

                    continue;
                }

                $hydratedMessage = $this->hydrateMessage($messagePayload);
                if (null === $hydratedMessage) {
                    $this->commandStore->markRejected($state->runId, $pendingCommand->idempotencyKey, 'Invalid command payload: malformed message envelope.');
                    $eventSpecs[] = [
                        'type' => 'agent_command_rejected',
                        'payload' => [
                            'kind' => $pendingCommand->kind,
                            'idempotency_key' => $pendingCommand->idempotencyKey,
                            'reason' => 'Invalid command payload: malformed message envelope.',
                        ],
                    ];

                    continue;
                }

                $messages[] = $hydratedMessage;
                $this->commandStore->markApplied($state->runId, $pendingCommand->idempotencyKey);
                $eventSpecs[] = [
                    'type' => 'agent_command_applied',
                    'payload' => [
                        'kind' => $pendingCommand->kind,
                        'idempotency_key' => $pendingCommand->idempotencyKey,
                        'options' => $pendingCommand->options,
                    ],
                ];

                $shouldContinue = true;

                continue;
            }

            if (!CoreCommandKind::isCore($pendingCommand->kind)) {
                $eventSpecs = [
                    ...$eventSpecs,
                    ...$this->applyExtensionCommand($state, $pendingCommand),
                ];
            }
        }

        return [$this->copyState($state, ['messages' => $messages]), $eventSpecs, $shouldContinue];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function isCancelSafeExtensionCommand(string $kind, array $options): bool
    {
        return !CoreCommandKind::isCore($kind)
            && true === ($options['cancel_safe'] ?? false);
    }

    /**
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    private function applyExtensionCommand(RunState $state, PendingCommand $command): array
    {
        $handler = $this->commandRouter->handlerFor($command->kind);
        if (null === $handler) {
            $this->commandStore->markRejected($state->runId, $command->idempotencyKey, 'No extension command handler registered.');

            return [[
                'type' => 'agent_command_rejected',
                'payload' => [
                    'kind' => $command->kind,
                    'idempotency_key' => $command->idempotencyKey,
                    'reason' => 'No extension command handler registered.',
                ],
            ]];
        }

        try {
            $mappedObjects = $handler->map($state->runId, $command->kind, $command->payload, $command->options);
        } catch (\Throwable $throwable) {
            $this->commandStore->markRejected($state->runId, $command->idempotencyKey, $throwable->getMessage());

            return [[
                'type' => 'agent_command_rejected',
                'payload' => [
                    'kind' => $command->kind,
                    'idempotency_key' => $command->idempotencyKey,
                    'reason' => $throwable->getMessage(),
                ],
            ]];
        }

        $this->commandStore->markApplied($state->runId, $command->idempotencyKey);

        $eventSpecs = [[
            'type' => 'agent_command_applied',
            'payload' => [
                'kind' => $command->kind,
                'idempotency_key' => $command->idempotencyKey,
                'options' => $command->options,
            ],
        ]];

        foreach ($mappedObjects as $mappedObject) {
            if (!$mappedObject instanceof RunEvent) {
                continue;
            }

            $eventSpecs[] = [
                'type' => $mappedObject->type,
                'payload' => $mappedObject->payload,
            ];
        }

        return $eventSpecs;
    }

    /**
     * @param list<PendingCommand> $pendingCommands
     *
     * @return array<string, true>
     */
    private function supersededSteerKeys(array $pendingCommands): array
    {
        if (self::SteerDrainAll === $this->steerDrainMode) {
            return [];
        }

        $steerCommands = array_values(array_filter(
            $pendingCommands,
            static fn (PendingCommand $pendingCommand): bool => CoreCommandKind::Steer === $pendingCommand->kind,
        ));

        if (\count($steerCommands) <= 1) {
            return [];
        }

        $latestSteerCommand = $steerCommands[\count($steerCommands) - 1];
        $superseded = [];

        foreach ($steerCommands as $steerCommand) {
            if ($steerCommand->idempotencyKey === $latestSteerCommand->idempotencyKey) {
                continue;
            }

            $superseded[$steerCommand->idempotencyKey] = true;
        }

        return $superseded;
    }

    private function continueRejectionReason(RunState $state): ?string
    {
        if (\in_array($state->status, [RunStatus::Running, RunStatus::Completed, RunStatus::Cancelled, RunStatus::Cancelling], true)) {
            return \sprintf('continue command is not allowed while run is %s.', $state->status->value);
        }

        if (RunStatus::Failed !== $state->status) {
            return 'continue command is only allowed from failed runs.';
        }

        if (!$state->retryableFailure) {
            return 'continue command requires a retryable failure state.';
        }

        $lastRole = $this->lastMessageRole($state);
        if (!\in_array($lastRole, ['user', 'tool'], true)) {
            return \sprintf(
                'continue command rejected: last message role must be "user" or "tool", got "%s".',
                $lastRole ?? 'none',
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function humanResponseMessage(array $payload): ?AgentMessage
    {
        if (!\array_key_exists('answer', $payload)) {
            return null;
        }

        $content = json_encode([
            'question_id' => \is_string($payload['question_id'] ?? null) ? $payload['question_id'] : null,
            'answer' => $payload['answer'],
        ]);

        if (false === $content) {
            $content = '{}';
        }

        return new AgentMessage(
            role: 'user',
            content: [[
                'type' => 'text',
                'text' => $content,
            ]],
            metadata: \is_string($payload['question_id'] ?? null)
                ? ['question_id' => $payload['question_id']]
                : [],
        );
    }

    private function lastMessageRole(RunState $state): ?string
    {
        if ([] === $state->messages) {
            return null;
        }

        $lastMessage = $state->messages[\count($state->messages) - 1] ?? null;

        return $lastMessage instanceof AgentMessage ? $lastMessage->role : null;
    }

    private function dispatchAdvance(string $runId, string $prefix): void
    {
        if (null === $this->commandBus) {
            return;
        }

        $stepId = \sprintf('%s-%d', $prefix, hrtime(true));

        try {
            $this->commandBus->dispatch(new AdvanceRun(
                runId: $runId,
                turnNo: 0,
                stepId: $stepId,
                attempt: 1,
                idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $stepId)),
            ));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to dispatch follow-up AdvanceRun command.', previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function copyState(RunState $state, array $overrides = []): RunState
    {
        return new RunState(
            runId: $overrides['runId'] ?? $state->runId,
            status: $overrides['status'] ?? $state->status,
            version: $overrides['version'] ?? $state->version,
            turnNo: $overrides['turnNo'] ?? $state->turnNo,
            lastSeq: $overrides['lastSeq'] ?? $state->lastSeq,
            isStreaming: $overrides['isStreaming'] ?? $state->isStreaming,
            streamingMessage: \array_key_exists('streamingMessage', $overrides)
                ? $overrides['streamingMessage']
                : $state->streamingMessage,
            pendingToolCalls: $overrides['pendingToolCalls'] ?? $state->pendingToolCalls,
            errorMessage: \array_key_exists('errorMessage', $overrides)
                ? $overrides['errorMessage']
                : $state->errorMessage,
            messages: $overrides['messages'] ?? $state->messages,
            activeStepId: \array_key_exists('activeStepId', $overrides)
                ? $overrides['activeStepId']
                : $state->activeStepId,
            retryableFailure: $overrides['retryableFailure'] ?? $state->retryableFailure,
        );
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

    /**
     * @param list<array{type: string, payload: array<string, mixed>, turn_no?: int}> $eventSpecs
     *
     * @return list<RunEvent>
     */
    private function eventsFromSpecs(string $runId, int $turnNo, int $startSeq, array $eventSpecs): array
    {
        $events = [];
        $seq = $startSeq;

        foreach ($eventSpecs as $eventSpec) {
            $eventTurnNo = \is_int($eventSpec['turn_no'] ?? null)
                ? $eventSpec['turn_no']
                : $turnNo;

            $events[] = $this->event(
                runId: $runId,
                seq: $seq,
                turnNo: $eventTurnNo,
                type: $eventSpec['type'],
                payload: $eventSpec['payload'],
            );

            ++$seq;
        }

        return $events;
    }

    /**
     * @return array{mode: ToolExecutionMode, timeout_seconds: int, max_parallelism: int}
     */
    private function resolveToolPolicy(string $toolName): array
    {
        if (null === $this->toolExecutionPolicyResolver) {
            return [
                'mode' => ToolExecutionMode::Sequential,
                'timeout_seconds' => 90,
                'max_parallelism' => 1,
            ];
        }

        $policy = $this->toolExecutionPolicyResolver->resolve($toolName);

        return [
            'mode' => $policy->mode,
            'timeout_seconds' => $policy->timeoutSeconds,
            'max_parallelism' => $policy->maxParallelism,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolveToolSchemas(string $runId, int $turnNo, string $stepId): array
    {
        if (null === $this->toolCatalogResolver) {
            return [];
        }

        $schemas = [];

        foreach ($this->toolCatalogResolver->resolve([
            'run_id' => $runId,
            'turn_no' => $turnNo,
            'step_id' => $stepId,
        ]) as $definition) {
            $schemas[$definition->name] = $definition->schema ?? ['type' => 'object'];
        }

        return $schemas;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function interruptPayloadFromToolResult(ToolCallResult $result): ?array
    {
        if ($result->isError || !\is_array($result->result)) {
            return null;
        }

        $details = \is_array($result->result['details'] ?? null)
            ? $result->result['details']
            : [];

        $interrupt = null;

        if ('interrupt' === ($details['kind'] ?? null)) {
            $interrupt = $details;
        }

        if (null === $interrupt && 'interrupt' === ($result->result['kind'] ?? null)) {
            $interrupt = $result->result;
        }

        if (null === $interrupt) {
            return null;
        }

        $questionId = \is_string($interrupt['question_id'] ?? null)
            ? $interrupt['question_id']
            : $result->toolCallId;

        $payload = [
            'tool_call_id' => $result->toolCallId,
            'tool_name' => \is_string($result->result['tool_name'] ?? null) ? $result->result['tool_name'] : null,
            'question_id' => $questionId,
            'prompt' => \is_string($interrupt['prompt'] ?? null) ? $interrupt['prompt'] : 'Human input required.',
            'schema' => \is_array($interrupt['schema'] ?? null) ? $interrupt['schema'] : ['type' => 'string'],
        ];

        return array_filter($payload, static fn (mixed $value): bool => null !== $value);
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
        return $this->copyState($state, [
            'version' => $state->version + 1,
            'lastSeq' => $state->lastSeq + $eventCount,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hydrateMessage(array $payload): ?AgentMessage
    {
        $role = $payload['role'] ?? null;
        $rawContent = $payload['content'] ?? null;

        if (!\is_string($role) || !\is_array($rawContent)) {
            return null;
        }

        $content = [];
        foreach ($rawContent as $contentPart) {
            if (!\is_array($contentPart)) {
                continue;
            }

            $content[] = $contentPart;
        }

        $timestamp = null;
        if (\is_string($payload['timestamp'] ?? null)) {
            try {
                $timestamp = new \DateTimeImmutable($payload['timestamp']);
            } catch (\Throwable) {
            }
        }

        return new AgentMessage(
            role: $role,
            content: $content,
            timestamp: $timestamp,
            name: \is_string($payload['name'] ?? null) ? $payload['name'] : null,
            toolCallId: \is_string($payload['tool_call_id'] ?? null) ? $payload['tool_call_id'] : null,
            toolName: \is_string($payload['tool_name'] ?? null) ? $payload['tool_name'] : null,
            details: $payload['details'] ?? null,
            isError: \is_bool($payload['is_error'] ?? null) ? $payload['is_error'] : false,
            metadata: \is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
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
