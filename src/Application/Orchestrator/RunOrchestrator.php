<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\OutboxProjector;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
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
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The RunOrchestrator coordinates the lifecycle of an agent run by processing commands and handling step results through a reducer pattern. It manages state transitions, tool execution policies, and message hydration while persisting events and commands to their respective stores.
 */
final readonly class RunOrchestrator
{
    private const string ScopeStartRun = 'command.start';
    private const string ScopeApplyCommand = 'command.apply';
    private const string ScopeAdvanceRun = 'command.advance';
    private const string ScopeLlmResult = 'result.llm';
    private const string ScopeToolResult = 'result.tool';
    private const string SteerDrainOneAtATime = 'one_at_a_time';
    private const string SteerDrainAll = 'all';

    /**
     * Initializes the orchestrator with required stores, reducer, and dispatcher.
     */
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
        private ?LoggerInterface $logger = null,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
    ) {
    }

    /**
     * Handles StartRun message to initialize a new agent run.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onStartRun(StartRun $message): void
    {
        $runId = $message->runId();

        $handle = function () use ($message, $runId): void {
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
        };

        if (null === $this->tracer) {
            $handle();

            return;
        }

        $this->tracer->inSpan('command.start_run', [
            'run_id' => $runId,
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
        ], $handle, root: true);
    }

    /**
     * Processes ApplyCommand message to modify run state.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onApplyCommand(ApplyCommand $message): void
    {
        $runId = $message->runId();

        $handle = function () use ($message, $runId): void {
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
        };

        if (null === $this->tracer) {
            $handle();

            return;
        }

        $this->tracer->inSpan('command.apply', [
            'run_id' => $runId,
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
            'command_kind' => $message->kind,
        ], $handle, root: true);
    }

    /**
     * Handles AdvanceRun message to trigger next step execution.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onAdvanceRun(AdvanceRun $message): void
    {
        $runId = $message->runId();

        $handle = function () use ($message, $runId): void {
            $this->runLockManager->synchronized($runId, function () use ($message, $runId): void {
                if ($this->idempotency->wasHandled(self::ScopeAdvanceRun, $runId, $message->idempotencyKey())) {
                    return;
                }

                $state = $this->runStore->get($runId) ?? RunState::queued($runId);
                [$preparedState, $boundaryEventSpecs] = null === $this->tracer
                    ? $this->applyPendingTurnStartCommands($state)
                    : $this->tracer->inSpan('command.application.turn_start_boundary', [
                        'run_id' => $runId,
                        'turn_no' => $state->turnNo,
                        'step_id' => $state->activeStepId,
                    ], fn (): array => $this->applyPendingTurnStartCommands($state))
                ;

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

                $this->metrics?->recordTurnStarted($runId, $nextTurnNo);
                $this->idempotency->markHandled(self::ScopeAdvanceRun, $runId, $message->idempotencyKey());
            });
        };

        if (null === $this->tracer) {
            $handle();

            return;
        }

        $this->tracer->inSpan('turn.orchestrator.advance', [
            'run_id' => $runId,
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
        ], $handle, root: true);
    }

    /**
     * Processes LlmStepResult message to update run state with LLM output.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onLlmStepResult(LlmStepResult $message): void
    {
        $runId = $message->runId();

        $handle = function () use ($message, $runId): void {
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

                    $this->metrics?->recordTurnCompleted($runId, $state->turnNo);
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

                    $this->metrics?->recordTurnCompleted($runId, $state->turnNo);
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

                    [$stateAfterBoundary, $boundaryEventSpecs, $shouldContinue] = null === $this->tracer
                        ? $this->applyPendingStopBoundaryCommands($stateAfterAssistant)
                        : $this->tracer->inSpan('command.application.stop_boundary', [
                            'run_id' => $runId,
                            'turn_no' => $state->turnNo,
                            'step_id' => $message->stepId(),
                        ], fn (): array => $this->applyPendingStopBoundaryCommands($stateAfterAssistant))
                    ;

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

                    $this->metrics?->recordTurnCompleted($runId, $state->turnNo);

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
        };

        if (null === $this->tracer) {
            $handle();

            return;
        }

        $this->tracer->inSpan('turn.orchestrator.llm_result', [
            'run_id' => $runId,
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
        ], $handle, root: true);
    }

    /**
     * Handles ToolCallResult message to process tool execution outcomes.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onToolCallResult(ToolCallResult $message): void
    {
        $runId = $message->runId();

        $handle = function () use ($message, $runId): void {
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

                    $this->metrics?->recordTurnCompleted($runId, $state->turnNo);
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

                if ($outcome->complete) {
                    $this->metrics?->recordTurnCompleted($runId, $state->turnNo);
                }

                if ([] !== $effects) {
                    $this->stepDispatcher->dispatchEffects($effects);
                }

                $this->idempotency->markHandled(self::ScopeToolResult, $runId, $message->idempotencyKey());
            });
        };

        if (null === $this->tracer) {
            $handle();

            return;
        }

        $this->tracer->inSpan('turn.orchestrator.tool_result', [
            'run_id' => $runId,
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
            'tool_call_id' => $message->toolCallId,
        ], $handle, root: true);
    }

    /**
     * Rejects a command with a specified reason.
     */
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
     * Applies cancel command to terminate the run.
     *
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
     * Applies continue command to resume the run.
     *
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
     * Applies human response command to inject user input.
     *
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
     * Processes pending commands that start a new turn.
     *
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
     * Processes pending commands that stop the current turn.
     *
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
     * Checks if an extension command is safe to cancel.
     *
     * @param array<string, mixed> $options
     */
    private function isCancelSafeExtensionCommand(string $kind, array $options): bool
    {
        return !CoreCommandKind::isCore($kind)
            && true === ($options['cancel_safe'] ?? false);
    }

    /**
     * Applies an extension command to the run state.
     *
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
     * Identifies steer keys superseded by pending commands.
     *
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

    /**
     * Determines if continuing the run is rejected and why.
     */
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
     * Hydrates a human response message from payload.
     *
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

    /**
     * Retrieves the role of the last message in the run state.
     */
    private function lastMessageRole(RunState $state): ?string
    {
        if ([] === $state->messages) {
            return null;
        }

        $lastMessage = $state->messages[\count($state->messages) - 1] ?? null;

        return $lastMessage instanceof AgentMessage ? $lastMessage->role : null;
    }

    /**
     * Dispatches an advance event for a run.
     */
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
     * Creates a copy of the run state with optional overrides.
     *
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
     * Persists state changes and events to stores.
     *
     * @param list<RunEvent> $events
     * @param list<object>   $effects
     */
    private function commit(RunState $state, RunState $nextState, array $events, array $effects = []): bool
    {
        $persist = function () use ($state, $nextState, $events, $effects): bool {
            if (!$this->runStore->compareAndSwap($nextState, $state->version)) {
                return false;
            }

            $eventsPersisted = false;

            try {
                if ([] !== $events) {
                    if (1 === \count($events)) {
                        $this->eventStore->append($events[0]);
                    } else {
                        $this->eventStore->appendMany($events);
                    }

                    $eventsPersisted = true;
                }
            } catch (\Throwable $exception) {
                $rollbackRestored = null;
                $rollbackError = null;

                try {
                    $rollbackRestored = $this->runStore->compareAndSwap($state, $nextState->version);
                } catch (\Throwable $rollbackException) {
                    $rollbackError = $rollbackException->getMessage();
                }

                $this->logger?->warning('agent_loop.commit.event_persist_failed', [
                    'run_id' => $nextState->runId,
                    'turn_no' => $nextState->turnNo,
                    'step_id' => $nextState->activeStepId,
                    'event_count' => \count($events),
                    'error' => $exception->getMessage(),
                    'rollback_restored' => $rollbackRestored,
                    'rollback_error' => $rollbackError,
                ]);

                return false;
            }

            if ($eventsPersisted) {
                try {
                    $this->outboxProjector->project($events);
                } catch (\Throwable $exception) {
                    $this->logger?->warning('agent_loop.commit.projection_failed', [
                        'run_id' => $nextState->runId,
                        'turn_no' => $nextState->turnNo,
                        'step_id' => $nextState->activeStepId,
                        'event_count' => \count($events),
                        'error' => $exception->getMessage(),
                    ]);
                }

                try {
                    $this->replayService->rebuildHotPromptState($nextState->runId);
                } catch (\Throwable $exception) {
                    $this->logger?->warning('agent_loop.commit.hot_state_rebuild_failed', [
                        'run_id' => $nextState->runId,
                        'turn_no' => $nextState->turnNo,
                        'step_id' => $nextState->activeStepId,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $this->logCommittedEvents($nextState, $events);

            if ([] !== $effects) {
                try {
                    $this->stepDispatcher->dispatchEffects($effects);
                } catch (\Throwable $exception) {
                    $this->logger?->warning('agent_loop.commit.effect_dispatch_failed', [
                        'run_id' => $nextState->runId,
                        'turn_no' => $nextState->turnNo,
                        'step_id' => $nextState->activeStepId,
                        'effects_count' => \count($effects),
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            try {
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
            } catch (\Throwable $exception) {
                $this->logger?->warning('agent_loop.commit.after_turn_commit_hook_failed', [
                    'run_id' => $nextState->runId,
                    'turn_no' => $nextState->turnNo,
                    'step_id' => $nextState->activeStepId,
                    'error' => $exception->getMessage(),
                ]);
            }

            return true;
        };

        $committed = null === $this->tracer
            ? $persist()
            : $this->tracer->inSpan('persistence.commit', [
                'run_id' => $nextState->runId,
                'turn_no' => $nextState->turnNo,
                'step_id' => $nextState->activeStepId,
                'event_count' => \count($events),
                'effects_count' => \count($effects),
            ], $persist)
        ;

        if (!$committed) {
            return false;
        }

        $this->trackCommitMetrics($state, $nextState, $events);

        return true;
    }

    /**
     * Updates in-process metrics from committed state and event transitions.
     *
     * @param list<RunEvent> $events
     */
    private function trackCommitMetrics(RunState $state, RunState $nextState, array $events): void
    {
        if (null === $this->metrics) {
            return;
        }

        $this->metrics->recordRunStatusTransition($state->status, $nextState->status);
        $this->metrics->setCommandQueueLag($nextState->runId, $this->commandStore->countPending($nextState->runId));

        $staleIgnored = 0;

        foreach ($events as $event) {
            if ('stale_result_ignored' !== $event->type) {
                continue;
            }

            ++$staleIgnored;
        }

        if ($staleIgnored > 0) {
            $this->metrics->incrementStaleResultCount($staleIgnored);
        }
    }

    /**
     * Emits structured log entries for committed run events.
     *
     * @param list<RunEvent> $events
     */
    private function logCommittedEvents(RunState $state, array $events): void
    {
        if (null === $this->logger || [] === $events) {
            return;
        }

        foreach ($events as $event) {
            $this->logger->info('agent_loop.event', [
                'run_id' => $event->runId,
                'turn_no' => $event->turnNo,
                'step_id' => $this->eventStepId($event, $state),
                'seq' => $event->seq,
                'status' => $state->status->value,
                'worker_id' => $this->eventWorkerId($event),
                'attempt' => $this->eventAttempt($event),
            ]);
        }
    }

    /**
     * Resolves the step identifier for structured event logging.
     */
    private function eventStepId(RunEvent $event, RunState $state): ?string
    {
        if (\is_string($event->payload['step_id'] ?? null) && '' !== $event->payload['step_id']) {
            return $event->payload['step_id'];
        }

        if (\is_string($event->payload['stepId'] ?? null) && '' !== $event->payload['stepId']) {
            return $event->payload['stepId'];
        }

        return $state->activeStepId;
    }

    /**
     * Resolves the worker identifier for structured event logging.
     */
    private function eventWorkerId(RunEvent $event): string
    {
        if (\is_string($event->payload['worker_id'] ?? null) && '' !== $event->payload['worker_id']) {
            return $event->payload['worker_id'];
        }

        return 'orchestrator';
    }

    /**
     * Resolves the execution attempt value for structured event logging.
     */
    private function eventAttempt(RunEvent $event): ?int
    {
        $attempt = $event->payload['attempt'] ?? null;

        if (\is_int($attempt)) {
            return $attempt;
        }

        if (\is_string($attempt) && ctype_digit($attempt)) {
            return (int) $attempt;
        }

        return null;
    }

    /**
     * Constructs a RunEvent with specified parameters.
     *
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
     * Generates multiple RunEvents from event specifications.
     *
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
     * Resolves the execution policy for a specific tool.
     *
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
     * Resolves tool schemas for a specific step.
     *
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
     * Extracts interrupt payload from a tool call result.
     *
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

    /**
     * Checks if a tool result is stale relative to current state.
     */
    private function isStaleResult(RunState $state, int $turnNo, string $stepId): bool
    {
        if ($state->turnNo !== $turnNo) {
            return true;
        }

        return null !== $state->activeStepId && $state->activeStepId !== $stepId;
    }

    /**
     * Increments the state version based on event count.
     */
    private function incrementStateVersion(RunState $state, int $eventCount): RunState
    {
        return $this->copyState($state, [
            'version' => $state->version + 1,
            'lastSeq' => $state->lastSeq + $eventCount,
        ]);
    }

    /**
     * Hydrates an AgentMessage from a payload array.
     *
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
     * Constructs an AgentMessage from assistant message data.
     *
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

    /**
     * Constructs an AgentMessage from a tool call result.
     */
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
     * Extracts tool calls from an assistant message.
     *
     * @param array<string, mixed> $assistantMessage
     *
     * @return list<array{
     * id: string,
     * name: string,
     * args: array<string, mixed>,
     * order_index: int,
     * tool_idempotency_key: string|null
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
