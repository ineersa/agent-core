<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
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
        private EventFactory $eventFactory,
        private ToolCallExtractor $toolCallExtractor,
        private AgentMessageNormalizer $messageNormalizer,
        private StepDispatcher $stepDispatcher,
        private ?ToolSetResolverInterface $toolSetResolver = null,
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

        if ($state->turnNo !== $message->turnNo() || (null !== $state->activeStepId && $state->activeStepId !== $message->stepId())) {
            $nextState = $this->eventFactory->incrementStateVersion($state, eventCount: 1);
            $event = $this->eventFactory->event(
                runId: $runId,
                seq: $nextState->lastSeq,
                turnNo: $state->turnNo,
                type: RunEventTypeEnum::StaleResultIgnored->value,
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
            // Do NOT append the aborted assistant message to the state
            // messages.  Aborted model output (including partial tool
            // calls) must never become part of the prompt history for
            // future turns.  If the TUI needs to display the aborted
            // partial output, use the LlmStepAborted event payload
            // (projection-only, not prompt context).
            //
            // Sanitized aborted assistant metadata is included in the
            // LlmStepAborted event below so future TUI/projection
            // consumers can display aborted partial output without it
            // entering model context.
            $abortedAssistantPayload = null;
            if (null !== $message->assistantMessage) {
                $toolCalls = $this->toolCallExtractor->extractToolCalls($message->assistantMessage);
                $text = $message->assistantMessage->asText() ?? '';
                $abortedAssistantPayload = [
                    'present' => true,
                    'text_length' => \strlen($text),
                    'text_sha256' => '' !== $text ? hash('sha256', $text) : null,
                    'has_tool_calls' => [] !== $toolCalls,
                    'tool_call_count' => \count($toolCalls),
                    'tool_call_ids' => [] !== $toolCalls
                        ? array_map(static fn (array $tc): string => $tc['id'], $toolCalls)
                        : [],
                    'has_thinking' => $message->assistantMessage->hasThinking(),
                ];
            }

            $eventSpecs = [
                [
                    'type' => RunEventTypeEnum::LlmStepAborted->value,
                    'payload' => [
                        'step_id' => $message->stepId(),
                        'stop_reason' => $message->stopReason ?? 'aborted',
                        'usage' => $message->usage,
                        'aborted_assistant' => $abortedAssistantPayload,
                    ],
                ],
                [
                    'type' => RunEventTypeEnum::AgentEnd->value,
                    'payload' => [
                        'reason' => 'cancelled',
                    ],
                ],
            ];

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);
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
                // Keep existing messages unchanged (no aborted assistant
                // message appended).
                messages: $state->messages,
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

            $event = $this->eventFactory->event(
                runId: $runId,
                seq: $nextState->lastSeq,
                turnNo: $nextState->turnNo,
                type: RunEventTypeEnum::LlmStepFailed->value,
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
        $toolCalls = $this->toolCallExtractor->extractToolCalls($assistantMessage);
        $toolSchemas = $this->resolveToolSchemas();

        $messages = $state->messages;
        $messages[] = $this->messageNormalizer->assistantMessage($assistantMessage);

        $pendingToolCalls = [];
        foreach ($toolCalls as $toolCall) {
            $pendingToolCalls[$toolCall['id']] = false;
        }

        $assistantMessagePayload = $this->messageNormalizer->assistantMessagePayload($assistantMessage);

        $activeSet = $this->resolveActiveSet($message->toolsRef, $state->turnNo, $runId);

        $effects = [];
        foreach ($toolCalls as $toolCall) {
            $policy = $this->resolveToolPolicy($toolCall['name'], $activeSet);

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
                toolsRef: $message->toolsRef,
            );
        }

        $eventSpecs = [[
            'type' => RunEventTypeEnum::LlmStepCompleted->value,
            'payload' => [
                'step_id' => $message->stepId(),
                'stop_reason' => $message->stopReason,
                'usage' => $message->usage,
                'tool_calls_count' => \count($toolCalls),
                'assistant_message' => $assistantMessagePayload,
                'text' => $assistantMessage->asText(),
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
                    'type' => RunEventTypeEnum::AgentEnd->value,
                    'payload' => [
                        'reason' => 'completed',
                    ],
                ];
            }

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

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
                'type' => RunEventTypeEnum::ToolExecutionStart->value,
                'payload' => [
                    'tool_call_id' => $effect->toolCallId,
                    'tool_name' => $effect->toolName,
                    'order_index' => $effect->orderIndex,
                    'mode' => $effect->mode,
                ],
            ];
        }

        $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

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
     * Resolve the execution policy for a tool from its registered definition.
     *
     * The execution mode comes from the tool's ToolDefinitionDTO (set by the
     * tool author/provider). Timeout and parallelism use fixed defaults
     * since these are managed by the ToolExecutor's own policy resolver.
     *
     * @return array{mode: ToolExecutionMode, timeout_seconds: int, max_parallelism: int}
     */
    private function resolveToolPolicy(string $toolName, ?ActiveToolSet $activeSet = null): array
    {
        $mode = ToolExecutionMode::Sequential;

        if (null !== $activeSet) {
            $modeValue = $activeSet->executionModes[$toolName] ?? ToolExecutionMode::Sequential->value;
            $mode = ToolExecutionMode::tryFrom($modeValue) ?? ToolExecutionMode::Sequential;
        }

        return [
            'mode' => $mode,
            'timeout_seconds' => 90,
            'max_parallelism' => 1,
        ];
    }

    /**
     * Resolve the active toolset for the current turn.
     */
    private function resolveActiveSet(?string $toolsRef, int $turnNo, string $runId): ?ActiveToolSet
    {
        if (null === $this->toolSetResolver || !\is_string($toolsRef) || '' === $toolsRef) {
            return null;
        }

        return $this->toolSetResolver->resolve($toolsRef, $turnNo, $runId);
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
