<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolExecutionPolicyResolver;
use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class LlmStepResultHandler implements RunMessageHandler
{
    public function __construct(
        private ToolBatchCollector $toolBatchCollector,
        private CommandMailboxPolicy $commandMailboxPolicy,
        private RunMessageStateTools $stateTools,
        private StepDispatcher $stepDispatcher,
        private ?ToolExecutionPolicyResolver $toolExecutionPolicyResolver = null,
        private ?ToolboxInterface $toolbox = null,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
        private ?MessageBusInterface $commandBus = null,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof LlmStepResult;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof LlmStepResult) {
            throw new \InvalidArgumentException('LlmStepResultHandler can only handle LlmStepResult messages.');
        }

        $runId = $message->runId();

        if ($this->stateTools->isStaleResult($state, $message->turnNo(), $message->stepId())) {
            $nextState = $this->stateTools->incrementStateVersion($state, eventCount: 1);
            $event = $this->stateTools->event(
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

            return new HandlerResult(
                nextState: $nextState,
                events: [$event],
            );
        }

        if ('aborted' === $message->stopReason || RunStatus::Cancelling === $state->status) {
            $messages = $state->messages;
            if (null !== $message->assistantMessage) {
                $messages[] = $this->stateTools->assistantMessage($message->assistantMessage);
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

            $events = $this->stateTools->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);
            $nextState = new RunState(
                runId: $state->runId,
                status: RunStatus::Cancelled,
                version: $state->version + 1,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq + \count($events),
                isStreaming: false,
                streamingMessage: null,
                pendingToolCalls: [],
                errorMessage: $state->errorMessage ?? 'Run cancelled during LLM streaming.',
                messages: $messages,
                activeStepId: $state->activeStepId,
                retryableFailure: false,
            );

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
                postCommit: $this->turnCompletedCallbacks($runId, $state->turnNo),
            );
        }

        if (null !== $message->error) {
            $errorMessage = \is_string($message->error['message'] ?? null)
                ? $message->error['message']
                : 'LLM worker failed.';
            $retryable = \is_bool($message->error['retryable'] ?? null)
                ? $message->error['retryable']
                : false;

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
                retryableFailure: $retryable,
            );

            $event = $this->stateTools->event(
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

            return new HandlerResult(
                nextState: $nextState,
                events: [$event],
                postCommit: $this->turnCompletedCallbacks($runId, $state->turnNo),
            );
        }

        $assistantMessage = $message->assistantMessage ?? new AssistantMessage();
        $toolCalls = $this->stateTools->extractToolCalls($assistantMessage);
        $toolSchemas = $this->resolveToolSchemas();

        $messages = $state->messages;
        $messages[] = $this->stateTools->assistantMessage($assistantMessage);

        $pendingToolCalls = [];
        foreach ($toolCalls as $toolCall) {
            $pendingToolCalls[$toolCall['id']] = false;
        }

        $assistantMessagePayload = $this->stateTools->assistantMessagePayload($assistantMessage);

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
                assistantMessage: $assistantMessagePayload,
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
                'assistant_message' => $assistantMessagePayload,
            ],
        ]];

        if ([] === $toolCalls) {
            $stateAfterAssistant = new RunState(
                runId: $state->runId,
                status: $state->status,
                version: $state->version,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq,
                isStreaming: $state->isStreaming,
                streamingMessage: $state->streamingMessage,
                pendingToolCalls: [],
                errorMessage: null,
                messages: $messages,
                activeStepId: $state->activeStepId,
                retryableFailure: false,
            );

            [$stateAfterBoundary, $boundaryEventSpecs, $shouldContinue] = null === $this->tracer
                ? $this->commandMailboxPolicy->applyPendingStopBoundaryCommands($stateAfterAssistant)
                : $this->tracer->inSpan('command.application.stop_boundary', [
                    'run_id' => $runId,
                    'turn_no' => $state->turnNo,
                    'step_id' => $message->stepId(),
                ], fn (): array => $this->commandMailboxPolicy->applyPendingStopBoundaryCommands($stateAfterAssistant))
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

            $events = $this->stateTools->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

            $nextState = new RunState(
                runId: $stateAfterBoundary->runId,
                status: $shouldContinue ? RunStatus::Running : RunStatus::Completed,
                version: $state->version + 1,
                turnNo: $stateAfterBoundary->turnNo,
                lastSeq: $state->lastSeq + \count($events),
                isStreaming: false,
                streamingMessage: null,
                pendingToolCalls: [],
                errorMessage: null,
                messages: $stateAfterBoundary->messages,
                activeStepId: $stateAfterBoundary->activeStepId,
                retryableFailure: false,
            );

            $postCommit = [
                ...$this->turnCompletedCallbacks($runId, $state->turnNo),
            ];

            $followUpAdvance = $shouldContinue ? $this->followUpAdvanceCallback($runId, 'stop-boundary-follow-up') : null;
            if (null !== $followUpAdvance) {
                $postCommit[] = $followUpAdvance;
            }

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
                postCommit: $postCommit,
            );
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

        $events = $this->stateTools->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

        $nextState = new RunState(
            runId: $state->runId,
            status: RunStatus::Running,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + \count($events),
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: $pendingToolCalls,
            errorMessage: null,
            messages: $messages,
            activeStepId: $state->activeStepId,
            retryableFailure: false,
        );

        $postCommit = [function () use ($runId, $state, $message, $effects): void {
            $initialEffects = $this->toolBatchCollector->registerExpectedBatch(
                $runId,
                $state->turnNo,
                $message->stepId(),
                $effects,
            );

            if ([] !== $initialEffects) {
                $this->stepDispatcher->dispatchEffects($initialEffects);
            }
        }];

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
            postCommit: $postCommit,
        );
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
    private function resolveToolSchemas(): array
    {
        if (null === $this->toolbox) {
            return [];
        }

        $schemas = [];

        foreach ($this->toolbox->getTools() as $tool) {
            if (!$tool instanceof Tool) {
                continue;
            }

            $schemas[$tool->getName()] = $tool->getParameters() ?? ['type' => 'object'];
        }

        return $schemas;
    }

    /**
     * @return list<callable(): void>
     */
    private function turnCompletedCallbacks(string $runId, int $turnNo): array
    {
        if (null === $this->metrics) {
            return [];
        }

        return [function () use ($runId, $turnNo): void {
            $this->metrics->recordTurnCompleted($runId, $turnNo);
        }];
    }

    private function followUpAdvanceCallback(string $runId, string $prefix): ?callable
    {
        if (null === $this->commandBus) {
            return null;
        }

        return function () use ($runId, $prefix): void {
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
        };
    }
}
