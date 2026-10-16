<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory;
use Ineersa\AgentCore\Application\Handler\PreparedToolTerminalAcceptanceDTO;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationCodec;
use Ineersa\AgentCore\Domain\Run\CurrentToolCallDTO;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationAcceptedCompletionIdentity;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class ToolCallResultHandler implements RunMessageHandler, RunMessageHandlerLogComponentInterface
{
    private const string SYNTHETIC_USER_CANCEL_MESSAGE = 'Tool execution cancelled by user.';

    private ToolExecutionEndPayloadCodec $toolExecutionEndPayloadCodec;

    public function __construct(
        private ToolBatchCollector $toolBatchCollector,
        private EventFactory $eventFactory,
        private ToolCallExtractor $toolCallExtractor,
        private AgentMessageNormalizer $messageNormalizer,
        private NormalizerInterface&DenormalizerInterface $serializer,
    ) {
        $this->toolExecutionEndPayloadCodec = new ToolExecutionEndPayloadCodec($this->serializer);
    }

    public function getLogComponent(): string
    {
        return 'tool';
    }

    public function supports(object $message): bool
    {
        return $message instanceof ToolCallResult;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof ToolCallResult) {
            throw new \InvalidArgumentException('ToolCallResultHandler can only handle ToolCallResult messages.');
        }

        $message = $this->toolExecutionEndPayloadCodec->finalizeResult($message);
        $runId = $message->runId();

        if (isset($state->pendingShellToolCalls[$message->toolCallId])) {
            return $this->handleShellResult($message, $state);
        }

        // Detached true terminals route before ordinary stale-step/duplicate rejection.
        // Admit them during Cancelling and already-terminal states without reviving the run.
        $detachedSettlement = null;
        $attachedEvidenceOnlySettlement = null;
        if (!$message->isHumanInputSuspension()) {
            $detached = $this->tryHandleDetachedTerminalAcceptance($message, $state);
            if (null !== $detached) {
                if (RunStatus::Cancelling === $state->status) {
                    if ([] !== $state->pendingToolCalls) {
                        // Keep cancel synthesis for remaining attached work and fold the
                        // detached receipt/actions into that same Cancelling transition.
                        $detachedSettlement = $detached;
                    } else {
                        return $this->withEmptyPendingCancellation($detached, $message, $state);
                    }
                } else {
                    return $detached;
                }
            } elseif ($state->status->isTerminal()) {
                // Attached deferred evidence can arrive after cancellation already won.
                $evidenceOnly = $this->tryHandleAttachedEvidenceOnlyAcceptance($message, $state);
                if (null !== $evidenceOnly) {
                    return $evidenceOnly;
                }
            }
        }

        // Completed or superseded tool results are harmless redeliveries. The
        // collector remains the authority for active parallel calls and HITL.
        if (($state->turnNo !== $message->turnNo() || $state->activeStepId !== $message->stepId())
            && RunStatus::Cancelling !== $state->status) {
            if (!$message->isHumanInputSuspension()) {
                $evidenceOnly = $this->tryHandleAttachedEvidenceOnlyAcceptance($message, $state);
                if (null !== $evidenceOnly) {
                    return $evidenceOnly;
                }
            }

            return new HandlerResult();
        }

        // Suspension envelopes are non-terminal: admit (or ignore) them before the
        // Cancelling ordinary-result branch so a null suspension body is never
        // committed as tool output and no question is admitted while cancelling.
        if ($message->isHumanInputSuspension()) {
            if (RunStatus::Cancelling === $state->status) {
                // Fall through to cancel synthesis with preserveIncoming=false.
            } else {
                return $this->handleHumanInputSuspension($message, $state);
            }
        }

        if (RunStatus::Cancelling === $state->status) {
            $messages = $state->messages;
            $toolCallInfoMap = $this->buildToolCallInfoMap($state);
            $pendingToolCalls = $state->pendingToolCalls;
            $projectedToolMessageCount = 0;
            $incomingAcceptance = null;

            // Suspension is not a finished tool result — never preserve its null body.
            $preserveIncoming = !$message->isHumanInputSuspension()
                && \array_key_exists($message->toolCallId, $pendingToolCalls)
                && false === $pendingToolCalls[$message->toolCallId];

            if ($preserveIncoming) {
                $pendingToolCalls[$message->toolCallId] = true;
                $incomingAcceptance = $this->prepareAttachedTerminalAcceptance($message);
            } elseif (!$message->isHumanInputSuspension() && null === $detachedSettlement) {
                // Call absent or already true: settle attached deferred evidence without
                // projecting a second original-call model result into cancel synthesis.
                $attachedEvidenceOnlySettlement = $this->tryHandleAttachedEvidenceOnlyAcceptance($message, $state);
            }

            $eventSpecs = [];
            foreach ([$detachedSettlement, $attachedEvidenceOnlySettlement] as $settlement) {
                if ($settlement instanceof HandlerResult) {
                    foreach ($settlement->events as $event) {
                        $eventSpecs[] = [
                            'type' => $event->type,
                            'payload' => $event->payload,
                            'turn_no' => $event->turnNo,
                        ];
                    }
                }
            }

            // Cancellation can land after some results were accepted while the batch was
            // still incomplete. Those ids are pendingToolCalls===true with durable
            // result_received + execution_end, but their tool messages were deferred until
            // batch completion and are therefore absent from RunState->messages. Terminalizing
            // without projecting them leaves the assistant tool_calls unmatched and every
            // later follow-up fails MalformedToolCallSequenceException::missingToolResults.
            // Project every durable-but-unprojected result (and synthesize cancelled results
            // for still-false ids) in order_index order before tool_batch_committed / agent_end.
            $batchIds = array_keys($pendingToolCalls);
            usort($batchIds, static function (string $a, string $b) use ($toolCallInfoMap): int {
                $orderA = isset($toolCallInfoMap[$a]['order_index']) && \is_int($toolCallInfoMap[$a]['order_index']) ? $toolCallInfoMap[$a]['order_index'] : 0;
                $orderB = isset($toolCallInfoMap[$b]['order_index']) && \is_int($toolCallInfoMap[$b]['order_index']) ? $toolCallInfoMap[$b]['order_index'] : 0;

                return $orderA <=> $orderB;
            });

            $collectorStepId = $state->activeStepId ?? $message->stepId();
            $syntheticStepId = $state->activeStepId ?? \sprintf('synthetic-cancel-%d', hrtime(true));

            foreach ($batchIds as $tcId) {
                $info = $toolCallInfoMap[$tcId] ?? null;
                $toolName = \is_string($info['name'] ?? null) ? $info['name'] : 'unknown';
                $orderIndex = \is_int($info['order_index'] ?? null) ? $info['order_index'] : 0;
                $alreadyTrue = true === ($pendingToolCalls[$tcId] ?? null);

                if ($alreadyTrue) {
                    $stored = $preserveIncoming && $tcId === $message->toolCallId
                        ? $message
                        : $this->toolBatchCollector->getStoredResult($runId, $state->turnNo, $collectorStepId, $tcId);

                    if ($this->stateContainsToolMessageForId($messages, $tcId)) {
                        // Already projected into messages — do not duplicate.
                        continue;
                    }

                    if ($preserveIncoming && $tcId === $message->toolCallId) {
                        // Incoming result has not been durably ended yet — full commit group.
                        $this->appendCommittedToolResultEvents(
                            eventSpecs: $eventSpecs,
                            messages: $messages,
                            result: $message,
                            acceptance: $incomingAcceptance,
                        );
                    } else {
                        // Result was accepted while Running (incomplete batch): ends already
                        // durable in prior events; project only the deferred tool message.
                        // Never re-emit result_received / execution_end for these ids.
                        // ponytail: collector store miss degrades message text to synthetic cancel;
                        // durable ToolExecutionEnd still carries the real result and the repair path rebuilds from it — rebuild here only if store loss proves real.
                        $resultToProject = $stored ?? $this->syntheticCancelledToolResult(
                            runId: $runId,
                            turnNo: $state->turnNo,
                            stepId: $syntheticStepId,
                            toolCallId: $tcId,
                            toolName: $toolName,
                            orderIndex: $orderIndex,
                        );
                        $this->appendToolMessage(messages: $messages, result: $resultToProject);
                    }
                    ++$projectedToolMessageCount;

                    continue;
                }

                $syntheticResult = $this->syntheticCancelledToolResult(
                    runId: $runId,
                    turnNo: $state->turnNo,
                    stepId: $syntheticStepId,
                    toolCallId: $tcId,
                    toolName: $toolName,
                    orderIndex: $orderIndex,
                );

                $this->appendCommittedToolResultEvents(
                    eventSpecs: $eventSpecs,
                    messages: $messages,
                    result: $syntheticResult,
                );
                ++$projectedToolMessageCount;
            }

            if ($projectedToolMessageCount > 0) {
                $eventSpecs[] = [
                    'type' => RunEventTypeEnum::ToolBatchCommitted->value,
                    'payload' => [
                        'count' => $projectedToolMessageCount,
                        'turn_no' => $state->turnNo,
                        'step_id' => $message->stepId(),
                    ],
                ];
            }

            $eventSpecs[] = [
                'type' => RunEventTypeEnum::AgentEnd->value,
                'payload' => [
                    'reason' => 'cancelled',
                ],
            ];

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);
            $nextState = $state->with([
                'status' => RunStatus::Cancelled,
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
                'isStreaming' => false,
                'streamingMessage' => null,
                'pendingToolCalls' => [],
                'currentToolCalls' => [],
                'pendingHumanInputRequests' => [],
                'messages' => $messages,
                'activeStepId' => null,
                'currentOperation' => null,
            ]);

            $postCommitActions = [];
            foreach ([$detachedSettlement, $attachedEvidenceOnlySettlement] as $settlement) {
                if ($settlement instanceof HandlerResult) {
                    foreach ($settlement->postCommitActions as $action) {
                        $postCommitActions[] = $action;
                    }
                }
            }
            $cancelBatch = $this->toolBatchCollector->prepareCancelFinalization($runId, $state->turnNo, $collectorStepId);
            if (null !== $cancelBatch) {
                $postCommitActions[] = $cancelBatch;
            }
            if ($incomingAcceptance instanceof PreparedToolTerminalAcceptanceDTO && null !== $incomingAcceptance->lifecycleAction) {
                $postCommitActions[] = $incomingAcceptance->lifecycleAction;
            }
            $postCancelAdvance = $this->postCancelAdvanceAction($runId, $state->turnNo);
            $postCommitActions[] = $postCancelAdvance;

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
                postCommitActions: $postCommitActions,
            );
        }

        $outcome = $this->toolBatchCollector->prepareCollect($message);
        if ($outcome->duplicate) {
            return new HandlerResult();
        }

        if ($outcome->complete && $this->canonicalBatchAlreadyCommitted($state, $message, $outcome->orderedResults)) {
            return new HandlerResult();
        }

        if (!$outcome->accepted) {
            return new HandlerResult();
        }

        $eventPayload = $this->toolExecutionEndPayloadCodec->toEventPayload($message);
        $resultFingerprint = ToolInvocationAcceptedCompletionIdentity::resultFingerprint($eventPayload['tool_result']);
        $acceptance = $this->toolBatchCollector->prepareTerminalAcceptance($message, $resultFingerprint);
        if ($acceptance instanceof PreparedToolTerminalAcceptanceDTO) {
            if (!$acceptance->accepted) {
                if ('conflicting_completion' === $acceptance->rejectionReason) {
                    throw new \RuntimeException(\sprintf('Conflicting accepted completion for run "%s" tool call "%s".', $message->runId(), $message->toolCallId));
                }

                return new HandlerResult();
            }
            if ($acceptance->detached) {
                // Detached results must not re-enter ordinary batch collection.
                return new HandlerResult();
            }
            if ($acceptance->duplicate) {
                return new HandlerResult();
            }
        }

        $eventSpecs = [[
            'type' => RunEventTypeEnum::ToolExecutionEnd->value,
            'payload' => $this->withAcceptedCompletionReceipt($eventPayload, $acceptance),
        ]];

        $pendingToolCalls = $state->pendingToolCalls;
        if (\array_key_exists($message->toolCallId, $pendingToolCalls)) {
            $pendingToolCalls[$message->toolCallId] = true;
        }

        $messages = $state->messages;
        $effects = $outcome->effectsToDispatch;
        // Ordinary sibling results must not drop WaitingHuman while any request is still pending.
        // Never produce Running with a non-empty pendingHumanInputRequests list.
        $status = [] !== $state->pendingHumanInputRequests
            ? RunStatus::WaitingHuman
            : RunStatus::Running;

        $postCommitActions = null !== $outcome->action ? [$outcome->action] : [];
        if ($acceptance instanceof PreparedToolTerminalAcceptanceDTO && null !== $acceptance->lifecycleAction) {
            $postCommitActions[] = $acceptance->lifecycleAction;
        }

        if ($outcome->complete) {
            $interruptPayload = null;

            foreach ($outcome->orderedResults as $orderedResult) {
                $notifications = ModelNotificationCodec::denormalizeFromDetails($this->serializer, $orderedResult->result['details'] ?? null);
                $toolMsg = $this->messageNormalizer->toolMessage($orderedResult, $notifications);
                $messages[] = $toolMsg;
                // Emit model_notification events for any notifications
                // attached to this tool result.
                foreach (ModelNotificationCodec::toEventSpecs($this->serializer, $notifications) as $notifSpec) {
                    $eventSpecs[] = $notifSpec;
                }

                $interruptPayload ??= $this->toolCallExtractor->interruptPayloadFromToolResult($orderedResult);
            }

            $eventSpecs[] = [
                'type' => RunEventTypeEnum::ToolBatchCommitted->value,
                'payload' => [
                    'count' => \count($outcome->orderedResults),
                    'turn_no' => $message->turnNo(),
                    'step_id' => $message->stepId(),
                ],
            ];

            $pendingToolCalls = [];

            $pendingHumanInputRequests = $state->pendingHumanInputRequests;
            if (null !== $interruptPayload) {
                $status = RunStatus::WaitingHuman;
                $eventSpecs[] = [
                    'type' => RunEventTypeEnum::WaitingHuman->value,
                    'payload' => $interruptPayload,
                ];
                // ask_human path: exactly one model-turn pending request for this interrupt.
                $pendingHumanInputRequests = [
                    PendingHumanInputRequestDTO::modelTurnFromInterruptPayload($interruptPayload),
                ];
            }

            if (null === $interruptPayload) {
                $followUpAdvance = $this->followUpAdvanceAction($runId, $state->turnNo);
                $postCommitActions[] = $followUpAdvance;
            }
        } else {
            $pendingHumanInputRequests = $state->pendingHumanInputRequests;
        }

        $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

        $currentToolCalls = $outcome->complete
            ? []
            : $this->withToolStatus($state->currentToolCalls, $message->toolCallId, RunOperationalToolCallStatusEnum::Completed);

        $nextState = $state->with([
            'status' => $status,
            'version' => $state->version + 1,
            'lastSeq' => $state->lastSeq + \count($events),
            'isStreaming' => false,
            'streamingMessage' => null,
            'pendingToolCalls' => $pendingToolCalls,
            'currentToolCalls' => $currentToolCalls,
            'messages' => $messages,
            'pendingHumanInputRequests' => $pendingHumanInputRequests,
        ]);

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
            postCommitEffects: $effects,
            postCommitActions: $postCommitActions,
        );
    }

    private function tryHandleDetachedTerminalAcceptance(ToolCallResult $message, RunState $state): ?HandlerResult
    {
        $eventPayload = $this->toolExecutionEndPayloadCodec->toEventPayload($message);
        $resultFingerprint = ToolInvocationAcceptedCompletionIdentity::resultFingerprint($eventPayload['tool_result']);
        $acceptance = $this->toolBatchCollector->prepareTerminalAcceptance($message, $resultFingerprint);
        if (!$acceptance instanceof PreparedToolTerminalAcceptanceDTO || !$acceptance->detached) {
            return null;
        }

        if (!$acceptance->accepted) {
            if ('conflicting_completion' === $acceptance->rejectionReason) {
                throw new \RuntimeException(\sprintf('Conflicting accepted completion for run "%s" tool call "%s".', $message->runId(), $message->toolCallId));
            }

            return new HandlerResult();
        }

        if ($acceptance->duplicate) {
            return new HandlerResult();
        }

        // C3 records the accepted terminal receipt under the original invocation
        // identity. Mailbox delivery and automatic wake remain later checkpoints.
        $eventSpecs = [[
            'type' => RunEventTypeEnum::ToolExecutionEnd->value,
            'payload' => $this->withAcceptedCompletionReceipt($eventPayload, $acceptance),
            'turn_no' => $acceptance->correlation->turnNo,
        ]];
        $events = $this->eventFactory->eventsFromSpecs(
            $message->runId(),
            $state->turnNo,
            $state->lastSeq + 1,
            $eventSpecs,
        );

        return new HandlerResult(
            nextState: $state->with([
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
            ]),
            events: $events,
            postCommitActions: null !== $acceptance->lifecycleAction ? [$acceptance->lifecycleAction] : [],
        );
    }

    private function tryHandleAttachedEvidenceOnlyAcceptance(ToolCallResult $message, RunState $state): ?HandlerResult
    {
        $acceptance = $this->prepareAttachedTerminalAcceptance($message);
        if (!$acceptance instanceof PreparedToolTerminalAcceptanceDTO) {
            return null;
        }

        // Evidence-only settlement records the accepted receipt without projecting the
        // original call into model history or reviving a cancelled/stale owner state.
        $eventPayload = $this->withAcceptedCompletionReceipt(
            $this->toolExecutionEndPayloadCodec->toEventPayload($message),
            $acceptance,
            evidenceOnly: true,
        );
        $eventSpecs = [[
            'type' => RunEventTypeEnum::ToolExecutionEnd->value,
            'payload' => $eventPayload,
            'turn_no' => $acceptance->correlation->turnNo,
        ]];
        $events = $this->eventFactory->eventsFromSpecs(
            $message->runId(),
            $state->turnNo,
            $state->lastSeq + 1,
            $eventSpecs,
        );

        return new HandlerResult(
            nextState: $state->with([
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
            ]),
            events: $events,
            postCommitActions: null !== $acceptance->lifecycleAction ? [$acceptance->lifecycleAction] : [],
        );
    }

    private function prepareAttachedTerminalAcceptance(ToolCallResult $message): ?PreparedToolTerminalAcceptanceDTO
    {
        $eventPayload = $this->toolExecutionEndPayloadCodec->toEventPayload($message);
        $resultFingerprint = ToolInvocationAcceptedCompletionIdentity::resultFingerprint($eventPayload['tool_result']);
        $acceptance = $this->toolBatchCollector->prepareTerminalAcceptance($message, $resultFingerprint);
        if (!$acceptance instanceof PreparedToolTerminalAcceptanceDTO || $acceptance->detached) {
            return null;
        }
        if (!$acceptance->accepted) {
            if ('conflicting_completion' === $acceptance->rejectionReason) {
                throw new \RuntimeException(\sprintf('Conflicting accepted completion for run "%s" tool call "%s".', $message->runId(), $message->toolCallId));
            }

            return null;
        }
        if ($acceptance->duplicate) {
            return null;
        }

        return $acceptance;
    }

    /**
     * Preserve sticky cancellation when a detached receipt arrives with no attached pending calls.
     * Settlement still records the detached receipt; the run ends Cancelled without revival.
     */
    private function withEmptyPendingCancellation(
        HandlerResult $detached,
        ToolCallResult $message,
        RunState $state,
    ): HandlerResult {
        $eventSpecs = [];
        foreach ($detached->events as $event) {
            $eventSpecs[] = [
                'type' => $event->type,
                'payload' => $event->payload,
                'turn_no' => $event->turnNo,
            ];
        }
        $eventSpecs[] = [
            'type' => RunEventTypeEnum::AgentEnd->value,
            'payload' => ['reason' => 'cancelled'],
        ];
        $events = $this->eventFactory->eventsFromSpecs(
            $message->runId(),
            $state->turnNo,
            $state->lastSeq + 1,
            $eventSpecs,
        );
        $collectorStepId = $state->activeStepId ?? $message->stepId();
        $postCommitActions = $detached->postCommitActions;
        $cancelBatch = $this->toolBatchCollector->prepareCancelFinalization($message->runId(), $state->turnNo, $collectorStepId);
        if (null !== $cancelBatch) {
            $postCommitActions[] = $cancelBatch;
        }
        $postCommitActions[] = $this->postCancelAdvanceAction($message->runId(), $state->turnNo);

        return new HandlerResult(
            nextState: $state->with([
                'status' => RunStatus::Cancelled,
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
                'isStreaming' => false,
                'streamingMessage' => null,
                'pendingToolCalls' => [],
                'currentToolCalls' => [],
                'pendingHumanInputRequests' => [],
                'activeStepId' => null,
                'currentOperation' => null,
            ]),
            events: $events,
            postCommitActions: $postCommitActions,
        );
    }

    /**
     * @param array{tool_result: array<string, mixed>} $payload
     *
     * @return array<string, mixed>
     */
    private function withAcceptedCompletionReceipt(
        array $payload,
        ?PreparedToolTerminalAcceptanceDTO $acceptance,
        bool $evidenceOnly = false,
    ): array {
        if (!$acceptance instanceof PreparedToolTerminalAcceptanceDTO || !$acceptance->accepted) {
            return $payload;
        }

        $payload['accepted_completion'] = [
            'completion_id' => $acceptance->completionId,
            'result_fingerprint' => $acceptance->resultFingerprint,
            'origin_run_id' => $acceptance->correlation->runId,
            'origin_turn_no' => $acceptance->correlation->turnNo,
            'origin_step_id' => $acceptance->correlation->stepId,
            'tool_call_id' => $acceptance->correlation->toolCallId,
            'invocation_id' => $acceptance->correlation->invocationId ?? $acceptance->correlation->deferredId,
            'detached' => $acceptance->detached,
            'evidence_only' => $evidenceOnly,
        ];

        return $payload;
    }

    private function handleShellResult(ToolCallResult $message, RunState $state): HandlerResult
    {
        $eventSpecs = [[
            'type' => RunEventTypeEnum::ToolExecutionEnd->value,
            'payload' => $this->toolExecutionEndPayloadCodec->toEventPayload($message),
        ]];

        $standalone = \is_array($message->result) && true === ($message->result['standalone'] ?? false);
        if ($standalone) {
            $eventSpecs[] = [
                'type' => RunEventTypeEnum::AgentEnd->value,
                'payload' => ['reason' => 'completed'],
            ];
        }

        $events = $this->eventFactory->eventsFromSpecs(
            $message->runId(),
            $state->turnNo,
            $state->lastSeq + 1,
            $eventSpecs,
        );
        $pendingShellToolCalls = $state->pendingShellToolCalls;
        unset($pendingShellToolCalls[$message->toolCallId]);
        $currentToolCalls = array_values(array_filter(
            $state->currentToolCalls,
            static fn (CurrentToolCallDTO $toolCall): bool => $toolCall->toolCallId !== $message->toolCallId,
        ));

        return new HandlerResult(
            nextState: $state->with([
                'status' => $standalone ? RunStatus::Completed : $state->status,
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
                'pendingToolCalls' => $standalone ? [] : $state->pendingToolCalls,
                'pendingShellToolCalls' => $pendingShellToolCalls,
                'currentToolCalls' => $currentToolCalls,
                'activeStepId' => $standalone ? null : $state->activeStepId,
                'currentOperation' => $standalone ? null : $state->currentOperation,
                'isStreaming' => false,
                'streamingMessage' => null,
            ]),
            events: $events,
            postCommitActions: $standalone ? [$this->shellCompletionAdvanceAction($message->runId(), $state->turnNo)] : [],
        );
    }

    private function handleHumanInputSuspension(ToolCallResult $message, RunState $state): HandlerResult
    {
        $request = $message->pendingHumanInput;
        if (null === $request || HumanInputContinuationKindEnum::ToolCall !== $request->continuationKind) {
            throw new \LogicException('ToolCallResult human-input suspension requires a ToolCall pending request.');
        }

        $refToolCallId = $request->continuationRef['tool_call_id'] ?? null;
        if ((\is_string($refToolCallId) && '' !== $refToolCallId && $refToolCallId !== $message->toolCallId)
            || $request->questionId !== ($request->payload['question_id'] ?? null)
            || !\array_key_exists($message->toolCallId, $state->pendingToolCalls)
            || true === $state->pendingToolCalls[$message->toolCallId]
        ) {
            throw new \LogicException(\sprintf('Cannot admit tool-execution suspension for invalid or resolved call "%s".', $message->toolCallId));
        }

        // Idempotency/conflict is keyed solely on tool_call_id. Question IDs need not be
        // globally unique across a run — two different calls may share a question_id.
        foreach ($state->pendingHumanInputRequests as $existing) {
            if (HumanInputContinuationKindEnum::ToolCall !== $existing->continuationKind) {
                continue;
            }
            $existingCallId = $existing->continuationRef['tool_call_id'] ?? null;
            if ($existingCallId !== $message->toolCallId) {
                continue;
            }
            if ($existing->questionId === $request->questionId
                && $existing->payload === $request->payload
                && $existing->continuationRef === $request->continuationRef
            ) {
                return new HandlerResult(nextState: null, events: []);
            }

            throw new \LogicException(\sprintf('Conflicting tool-execution suspension for call "%s": existing request "%s", new request "%s".', $message->toolCallId, $existing->questionId, $request->questionId));
        }

        $prepared = $this->toolBatchCollector->prepareHumanInputSuspension(
            $message->runId(),
            $message->turnNo(),
            $message->stepId(),
            $message->toolCallId,
            $request->questionId,
        );
        $events = $this->eventFactory->eventsFromSpecs($message->runId(), $state->turnNo, $state->lastSeq + 1, [[
            'type' => RunEventTypeEnum::WaitingHuman->value,
            'payload' => $request->waitingHumanEventPayload(),
        ]]);

        // Capture pending request count before append so FIFO index of the newly admitted
        // request is deterministic (equals count-before; append is always at the tail).
        $pendingRequestCountBefore = \count($state->pendingHumanInputRequests);
        $pendingHumanInputRequests = [...$state->pendingHumanInputRequests, $request];
        \assert($pendingRequestCountBefore + 1 === \count($pendingHumanInputRequests));

        return new HandlerResult(
            nextState: $state->with([
                'status' => RunStatus::WaitingHuman,
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
                'isStreaming' => false,
                'streamingMessage' => null,
                'currentToolCalls' => $this->withToolStatus($state->currentToolCalls, $message->toolCallId, RunOperationalToolCallStatusEnum::WaitingHuman),
                'pendingHumanInputRequests' => $pendingHumanInputRequests,
            ]),
            events: $events,
            postCommitEffects: $prepared->effects,
            postCommitActions: null !== $prepared->action ? [$prepared->action] : [],
        );
    }

    /**
     * @param list<CurrentToolCallDTO> $toolCalls
     *
     * @return list<CurrentToolCallDTO>
     */
    private function withToolStatus(array $toolCalls, string $toolCallId, RunOperationalToolCallStatusEnum $status): array
    {
        return array_map(
            static fn (CurrentToolCallDTO $toolCall): CurrentToolCallDTO => $toolCall->toolCallId === $toolCallId
                ? $toolCall->withStatus($status)
                : $toolCall,
            $toolCalls,
        );
    }

    /**
     * @param list<ToolCallResult> $orderedResults
     */
    private function canonicalBatchAlreadyCommitted(RunState $state, ToolCallResult $message, array $orderedResults): bool
    {
        if ([] === $orderedResults) {
            return false;
        }

        if ($state->turnNo !== $message->turnNo() || $state->activeStepId !== $message->stepId()) {
            return false;
        }

        foreach ($orderedResults as $orderedResult) {
            if (!$this->stateContainsCommittedToolResult($state, $orderedResult)) {
                return false;
            }
        }

        return true;
    }

    private function stateContainsCommittedToolResult(RunState $state, ToolCallResult $result): bool
    {
        foreach ($state->messages as $message) {
            if ('tool' !== $message->role) {
                continue;
            }

            if ($message->toolCallId !== $result->toolCallId) {
                continue;
            }

            $orderIndex = $message->metadata['order_index'] ?? null;
            if (!\is_int($orderIndex) || $orderIndex !== $result->orderIndex) {
                continue;
            }

            if ($message->isError !== $result->isError) {
                continue;
            }

            if ($message->details !== $result->result) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildToolCallInfoMap(RunState $state): array
    {
        $toolCallInfoMap = [];
        foreach (array_reverse($state->messages) as $replayMsg) {
            if ('assistant' === $replayMsg->role && \is_array($replayMsg->metadata['tool_calls'] ?? null)) {
                foreach ($replayMsg->metadata['tool_calls'] as $tc) {
                    if (\is_string($tc['id'] ?? null)) {
                        $toolCallInfoMap[$tc['id']] = $tc;
                    }
                }
                break;
            }
        }

        return $toolCallInfoMap;
    }

    /**
     * @param list<array{type: string, payload: array<string, mixed>}> $eventSpecs
     * @param list<AgentMessage>                                       $messages
     */
    private function appendCommittedToolResultEvents(
        array &$eventSpecs,
        array &$messages,
        ToolCallResult $result,
        ?PreparedToolTerminalAcceptanceDTO $acceptance = null,
    ): void {
        $eventSpecs[] = [
            'type' => RunEventTypeEnum::ToolExecutionEnd->value,
            'payload' => $this->withAcceptedCompletionReceipt(
                $this->toolExecutionEndPayloadCodec->toEventPayload($result),
                $acceptance,
            ),
        ];
        $this->appendToolMessage(messages: $messages, result: $result);
    }

    /** @param list<AgentMessage> $messages */
    private function appendToolMessage(array &$messages, ToolCallResult $result): void
    {
        $notifications = ModelNotificationCodec::denormalizeFromDetails($this->serializer, $result->result['details'] ?? null);
        $messages[] = $this->messageNormalizer->toolMessage($result, $notifications);
    }

    /**
     * @param list<AgentMessage> $messages
     */
    private function stateContainsToolMessageForId(array $messages, string $toolCallId): bool
    {
        foreach ($messages as $message) {
            if ('tool' === $message->role && $message->toolCallId === $toolCallId) {
                return true;
            }
        }

        return false;
    }

    private function syntheticCancelledToolResult(
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
        string $toolName,
        int $orderIndex,
    ): ToolCallResult {
        $cancelMessage = self::SYNTHETIC_USER_CANCEL_MESSAGE;

        return new ToolCallResult(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('cancel-%s-%s', $runId, $toolCallId)),
            toolCallId: $toolCallId,
            orderIndex: $orderIndex,
            result: [
                'tool_name' => $toolName,
                'content' => [['type' => 'text', 'text' => $cancelMessage]],
            ],
            isError: true,
            error: [
                'type' => 'cancelled',
                'message' => $cancelMessage,
            ],
        );
    }

    private function shellCompletionAdvanceAction(string $runId, int $turnNo): DispatchCoordinationMessageDTO
    {
        return AdvanceRunCoordinationFactory::create($runId, $turnNo, 'shell-standalone-advance');
    }

    private function postCancelAdvanceAction(string $runId, int $turnNo): DispatchCoordinationMessageDTO
    {
        return AdvanceRunCoordinationFactory::create($runId, $turnNo, 'post-cancel-advance');
    }

    private function followUpAdvanceAction(string $runId, int $turnNo): DispatchCoordinationMessageDTO
    {
        return AdvanceRunCoordinationFactory::create($runId, $turnNo, 'advance-after-tools');
    }
}
