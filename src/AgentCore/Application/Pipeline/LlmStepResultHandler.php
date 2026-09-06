<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\AdvanceRunCallbackFactory;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolExecutionPolicyResolver;
use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionSettingsInterface;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationCodec;
use Ineersa\AgentCore\Domain\Run\CurrentToolCallDTO;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\ToolBatchIdentity;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class LlmStepResultHandler implements RunMessageHandler, RunMessageHandlerLogComponentInterface
{
    public function __construct(
        private ToolBatchCollector $toolBatchCollector,
        private CommandMailboxPolicy $commandMailboxPolicy,
        private EventFactory $eventFactory,
        private ToolCallExtractor $toolCallExtractor,
        private AgentMessageNormalizer $messageNormalizer,
        private StepDispatcher $stepDispatcher,
        private NormalizerInterface $normalizer,
        private ?ToolSetResolverInterface $toolSetResolver = null,
        private ?ToolboxInterface $toolbox = null,
        private ?RunTracer $tracer = null,
        private ?MessageBusInterface $commandBus = null,
        private ?ToolExecutionSettingsInterface $toolExecutionSettings = null,
        private int $maxParallelism = 1,
    ) {
    }

    public function getLogComponent(): string
    {
        return 'llm';
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

        // A result is valid only while its exact LLM operation remains active.
        // Once committed, redelivery is a successful no-op: it must not append a
        // stale event or schedule a second tool/continuation path.
        if (!$state->canAcceptLlmResult($message)) {
            return new HandlerResult();
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
                        ...$this->availableToolsPayload($message),
                    ],
                ],
                [
                    'type' => RunEventTypeEnum::AgentEnd->value,
                    'payload' => [
                        'reason' => 'cancelled',
                    ],
                ],
            ];

            // Emit generic model_notification events for any
            // notifications produced by transform context hooks
            // during this LLM step (e.g. defense-in-depth output caps).
            foreach (ModelNotificationCodec::toEventSpecs($this->normalizer, $message->modelNotifications) as $notifSpec) {
                $eventSpecs[] = $notifSpec;
            }

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);
            $nextState = $state->with([
                'status' => RunStatus::Cancelled,
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
                'isStreaming' => false,
                'streamingMessage' => null,
                'pendingToolCalls' => [],
                'currentToolCalls' => [],
                'activeStepId' => null,
                'currentOperation' => null,
                // Keep existing messages unchanged (no aborted assistant
                // message appended).
                'errorMessage' => $state->errorMessage ?? 'Run cancelled during LLM streaming.',
            ]);

            // Match ToolCallResultHandler / immediate-cancel: wake AdvanceRun so
            // an already-queued AppendMessage drains after AgentEnd(cancelled).
            $postCommit = [];
            $postCancelAdvance = $this->followUpAdvanceCallback($runId, $state->turnNo, 'post-cancel-advance');
            if (null !== $postCancelAdvance) {
                $postCommit[] = $postCancelAdvance;
            }

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
                postCommit: $postCommit,
            );
        }

        if (null !== $message->error) {
            $error = $message->error;
            $errorMessage = \is_string($error['message'] ?? null)
                ? $error['message']
                : 'LLM worker failed.';
            $userMessage = \is_string($error['user_message'] ?? null) ? $error['user_message'] : $errorMessage;

            // Transport-owned retries never reach this handler while still retryable.
            // Any LlmStepResult error here is terminal for the run.
            $error['retryable'] = false;

            $eventPayload = [
                'error' => $error,
                'retryable' => false,
                'step_id' => $message->stepId(),
                'model' => $message->model,
                'reasoning' => $message->reasoning,
                ...$this->availableToolsPayload($message),
            ];

            $eventSpecs = [[
                'type' => RunEventTypeEnum::LlmStepFailed->value,
                'payload' => $eventPayload,
            ]];

            foreach (ModelNotificationCodec::toEventSpecs($this->normalizer, $message->modelNotifications) as $notifSpec) {
                $eventSpecs[] = $notifSpec;
            }

            $eventSpecs[] = [
                'type' => RunEventTypeEnum::AgentEnd->value,
                'payload' => [
                    'reason' => 'failed',
                    'error' => $userMessage,
                ],
            ];

            $nextState = $state->with([
                'status' => RunStatus::Failed,
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($eventSpecs),
                'isStreaming' => false,
                'streamingMessage' => null,
                'pendingToolCalls' => [],
                'currentToolCalls' => [],
                'activeStepId' => null,
                'currentOperation' => null,
                'errorMessage' => $userMessage,
            ]);

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

            // Context-overflow is a visible LLM failure. Do not schedule
            // CompactRun recovery or hide the provider rejection.

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
            );
        }

        $assistantMessage = $message->assistantMessage ?? new AssistantMessage();
        $toolCalls = $this->toolCallExtractor->extractToolCalls($assistantMessage);
        $toolSchemas = $this->resolveToolSchemas();

        $messages = $state->messages;
        $messages[] = $this->messageNormalizer->assistantMessage($assistantMessage);

        $pendingToolCalls = [];
        $currentToolCalls = [];
        foreach ($toolCalls as $toolCall) {
            $pendingToolCalls[$toolCall['id']] = false;
            $currentToolCalls[] = new CurrentToolCallDTO(
                ToolBatchIdentity::fromTurnAndStep($state->turnNo, $message->stepId()),
                $toolCall['id'],
                $toolCall['order_index'],
                RunOperationalToolCallStatusEnum::Running,
                $message->attempt(),
            );
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
                attempt: $message->attempt(),
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
                // The parent model inherited by tool-launched children must
                // be the model that actually produced this LLM result, not
                // the historical RunState model (which may be stale after a
                // session-level model change).
                parentModel: $message->model,
            );
        }

        $eventSpecs = [[
            'type' => RunEventTypeEnum::LlmStepCompleted->value,
            'payload' => [
                'step_id' => $message->stepId(),
                'stop_reason' => $message->stopReason,
                'model' => $message->model,
                'reasoning' => $message->reasoning,
                'usage' => $message->usage,
                'assistant_message' => $assistantMessagePayload,
                ...$this->availableToolsPayload($message),
            ],
        ]];

        // Emit generic model_notification events for notifications
        // produced by transform context hooks during this LLM step.
        // These carry the same generic shape as ToolCallResultHandler
        // emissions and project to the same model.notification runtime
        // event / System transcript block.
        foreach (ModelNotificationCodec::toEventSpecs($this->normalizer, $message->modelNotifications) as $notifSpec) {
            $eventSpecs[] = $notifSpec;
        }

        if ([] === $toolCalls) {
            $stateAfterAssistant = $state->with([
                'pendingToolCalls' => [],
                'currentToolCalls' => [],
                'errorMessage' => null,
                'messages' => $messages,
            ]);

            $mailboxResult = null === $this->tracer
                ? $this->commandMailboxPolicy->applyPendingStopBoundaryCommands($stateAfterAssistant)
                : $this->tracer->inSpan('command.application.stop_boundary', [
                    'run_id' => $runId,
                    'turn_no' => $state->turnNo,
                    'step_id' => $message->stepId(),
                ], fn (): CommandApplicationResult => $this->commandMailboxPolicy->applyPendingStopBoundaryCommands($stateAfterAssistant))
            ;

            $stateAfterBoundary = $mailboxResult->state;
            $boundaryEventSpecs = $mailboxResult->eventSpecs;
            $shouldContinue = $mailboxResult->shouldContinue;
            $mailboxEffects = $mailboxResult->effects;

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

            // The mailbox result is the same lineage as $state (same version/
            // lastSeq), so with() on it is equivalent to the previous mixed
            // construction.
            $nextState = $stateAfterBoundary->with([
                'status' => $shouldContinue ? RunStatus::Running : RunStatus::Completed,
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
                'isStreaming' => false,
                'streamingMessage' => null,
                'pendingToolCalls' => [],
                'currentToolCalls' => [],
                'currentOperation' => null,
                'errorMessage' => null,
            ]);

            $postCommit = [];

            $followUpAdvance = $shouldContinue ? $this->followUpAdvanceCallback($runId, $state->turnNo, 'stop-boundary-follow-up') : null;
            if (null !== $followUpAdvance) {
                $postCommit[] = $followUpAdvance;
            }

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
                effects: $mailboxEffects,
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
                    'attempt' => $effect->attempt(),
                    'mode' => $effect->mode,
                ],
            ];
        }

        $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

        $nextState = $state->with([
            'status' => RunStatus::Running,
            'version' => $state->version + 1,
            'lastSeq' => $state->lastSeq + \count($events),
            'isStreaming' => false,
            'streamingMessage' => null,
            'pendingToolCalls' => $pendingToolCalls,
            'currentToolCalls' => $currentToolCalls,
            'currentOperation' => null,
            'errorMessage' => null,
            'messages' => $messages,
        ]);

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
     * The execution mode comes from the tool's ToolDefinitionDTO via ActiveToolSet.
     * Per-tool timeout overrides come from ActiveToolSet.timeoutSeconds when set.
     * Absent overrides mean no per-call cooperative timeout budget (null).
     *
     * @return array{mode: ToolExecutionMode, timeout_seconds: ?int, max_parallelism: int}
     */
    private function resolveToolPolicy(string $toolName, ?ActiveToolSet $activeSet = null): array
    {
        $mode = ToolExecutionMode::Sequential;
        $timeoutSeconds = null;
        $maxParallelism = max(1, $this->maxParallelism);

        if (null !== $this->toolExecutionSettings) {
            $defaults = ToolExecutionPolicyResolver::fromSettings($this->toolExecutionSettings)->resolve($toolName);
            $maxParallelism = $defaults->maxParallelism;
        }

        if (null !== $activeSet) {
            $modeValue = $activeSet->executionModes[$toolName] ?? ToolExecutionMode::Sequential->value;
            $mode = ToolExecutionMode::tryFrom($modeValue) ?? ToolExecutionMode::Sequential;
            if (isset($activeSet->timeoutSeconds[$toolName]) && $activeSet->timeoutSeconds[$toolName] > 0) {
                $timeoutSeconds = $activeSet->timeoutSeconds[$toolName];
            }
        }

        return [
            'mode' => $mode,
            'timeout_seconds' => null !== $timeoutSeconds && $timeoutSeconds > 0 ? max(1, $timeoutSeconds) : null,
            'max_parallelism' => max(1, $maxParallelism),
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

    private function followUpAdvanceCallback(string $runId, int $turnNo, string $prefix): ?callable
    {
        if (null === $this->commandBus) {
            return null;
        }

        return AdvanceRunCallbackFactory::create($this->commandBus, $runId, $turnNo, $prefix, 'Failed to dispatch follow-up AdvanceRun command.');
    }

    /**
     * Compact privacy-safe available-tools snapshot for canonical LLM events.
     *
     * Omitted entirely when the request had no provider-visible tools so old
     * sessions and no-tool calls stay free of empty noise.
     *
     * @return array{available_tools?: list<string>, available_tools_schema_tokens_estimate?: int}
     */
    private function availableToolsPayload(LlmStepResult $message): array
    {
        if ([] === $message->availableTools) {
            return [];
        }

        return [
            'available_tools' => $message->availableTools,
            'available_tools_schema_tokens_estimate' => $message->availableToolsSchemaTokensEstimate,
        ];
    }
}
