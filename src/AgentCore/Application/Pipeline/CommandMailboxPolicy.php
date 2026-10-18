<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Run\RunState;

final readonly class CommandMailboxPolicy
{
    public function __construct(
        private CommandStoreInterface $commandStore,
        private CommandRouter $commandRouter,
        private \Ineersa\AgentCore\Application\Tool\ToolCompletionMailboxConsumer $toolCompletions,
        private \Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryEligibilityPolicy $completionEligibility,
    ) {
    }

    public function completionContinuationIneligibleReason(RunState $state, \Ineersa\AgentCore\Domain\Message\AdvanceRun $message): ?string
    {
        if (null === $message->completionFence) {
            return null;
        }
        if (null !== $message->appliedCompletion && !$this->toolCompletions->isAppliedContinuation($state->runId, $message->appliedCompletion)) {
            return 'completion_not_applied';
        }

        return $message->completionMailboxDrain
            ? $this->completionEligibility->fenceIneligibleReason($state->runId, $message->completionFence)
            : $this->completionEligibility->ineligibleReason($state, $message->completionFence);
    }

    /** Manual compaction owns maintenance authority; the applied result keeps its separate wake fence. */
    public function deferCompletionToCompaction(CommandApplicationResult $result, ?\Ineersa\AgentCore\Domain\Message\AdvanceRun $existing = null): CommandApplicationResult
    {
        $reference = $existing->appliedCompletion ?? $result->appliedCompletion();
        if (null === $reference || null === $reference->deliveryFence
            || !array_any($result->effects, static fn (object $effect): bool => $effect instanceof CompactRun)) {
            return $result;
        }
        $continuation = \Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory::forCompletion($result->state, $reference->deliveryFence, appliedCompletion: $reference)->message;
        if (!$continuation instanceof \Ineersa\AgentCore\Domain\Message\AdvanceRun) {
            throw new \LogicException('Completion coordination must capture an AdvanceRun.');
        }
        $effects = array_map(static fn (object $effect): object => $effect instanceof CompactRun ? new CompactRun(
            runId: $effect->runId(), turnNo: $effect->turnNo(), stepId: $effect->stepId(),
            attempt: $effect->attempt(), idempotencyKey: $effect->idempotencyKey(),
            trigger: $effect->trigger, continueAfterCompaction: $effect->continueAfterCompaction,
            customInstructions: $effect->customInstructions, completionContinuation: $continuation,
        ) : $effect, $result->effects);

        return new CommandApplicationResult($result->state, $result->eventSpecs, $result->shouldContinue, $effects, $result->postCommitActions, $result->completionBytes);
    }

    /** Preserve automatic intent on every compaction settlement, including skips and failures. */
    public function afterCompactionSettlement(HandlerResult $result, ?\Ineersa\AgentCore\Domain\Message\AdvanceRun $continuation): HandlerResult
    {
        $state = $result->nextState;
        if (null === $state || [] === $result->events || null !== $state->currentOperation
            || \Ineersa\AgentCore\Domain\Run\RunStatus::Compacting === $state->status) {
            return $result;
        }
        $effects = $result->effects;
        $actions = $result->postCommitActions;
        $events = $result->events;
        if (null !== $continuation) {
            // A held completion turn is not a fresh human continuation. Never
            // replace its cancellation/history/controller authority at settlement.
            $effects = array_values(array_filter($effects, static fn (object $effect): bool => !$effect instanceof \Ineersa\AgentCore\Domain\Message\AdvanceRun));
            if (null === $this->completionContinuationIneligibleReason($state, $continuation)) {
                $actions[] = \Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory::forCompletion($state, $continuation->completionFence, appliedCompletion: $continuation->appliedCompletion);
            } elseif (\Ineersa\AgentCore\Domain\Run\RunStatus::Running === $state->status) {
                // A synchronous manual skip never entered Compacting. Close
                // the held completion turn when only its wake authority expired.
                $events[] = RunEvent::forAppend($state->runId, $state->turnNo, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']);
                $state = $state->with([
                    'status' => \Ineersa\AgentCore\Domain\Run\RunStatus::Completed,
                    'lastSeq' => $state->lastSeq + 1,
                    'activeStepId' => null,
                    'isStreaming' => false,
                    'streamingMessage' => null,
                ]);
            }
        } elseif (\Ineersa\AgentCore\Domain\Run\RunStatus::Completed === $state->status
            && null === $state->parentRunId
            && [] === $state->pendingHumanInputRequests && !$state->isStreaming) {
            // Receipts admitted during maintenance could not schedule an idle
            // successor. Reuse that same coalesced path once maintenance settles.
            foreach ($this->commandStore->pending($state->runId) as $command) {
                $reference = $command->payload['reference'] ?? null;
                if (CoreCommandKind::ToolCompletion === $command->kind
                    && $reference instanceof \Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO
                    && null !== $reference->deliveryFence
                    && null === $this->completionEligibility->ineligibleReason($state, $reference->deliveryFence)) {
                    $actions[] = \Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory::forCompletion($state, $reference->deliveryFence);
                    break;
                }
            }
        }

        return new HandlerResult($state, $events, $effects, $result->postCommitEffects, $actions);
    }

    public function applyPendingTurnStartCommands(RunState $state): CommandApplicationResult
    {
        $result = $this->applyPendingCommands($state, CommandApplicationBoundary::TurnStart);

        return $result;
    }

    public function applyPendingStopBoundaryCommands(RunState $state): CommandApplicationResult
    {
        $result = $this->applyPendingCommands($state, CommandApplicationBoundary::StopBoundary);

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function isCancelSafeExtensionCommand(string $kind, array $options): bool
    {
        return !CoreCommandKind::isCore($kind)
            && true === ($options['cancel_safe'] ?? false);
    }

    /**
     * Unified command application loop parameterized by boundary semantics.
     *
     * Both applyPendingTurnStartCommands() and applyPendingStopBoundaryCommands()
     * delegate here. The CommandApplicationBoundary controls the shouldContinue
     * tracking that distinguishes stop-boundary from turn-start behavior.
     *
     * @return CommandApplicationResult containing mutated state, event specs,
     *                                  shouldContinue flag, and outbound effects (e.g. CompactRun).
     */
    private function applyPendingCommands(RunState $state, CommandApplicationBoundary $boundary): CommandApplicationResult
    {
        // Safe-boundary cutoff under the outer per-run lock: only commands
        // already durably enqueued (agent_command_queued) before this pending()
        // snapshot are drained here, in store FIFO order. Later enqueues wait
        // for the next turn-start or stop boundary.
        $pendingCommands = $this->commandStore->pending($state->runId);

        $messages = $state->messages;
        $eventSpecs = [];
        $effects = [];
        $shouldContinue = false;
        $actions = [];
        $completionBytes = 0;
        $completionDecisions = 0;
        $completionDrainScheduled = false;

        foreach ($pendingCommands as $pendingCommand) {
            if (CoreCommandKind::ToolCompletion === $pendingCommand->kind) {
                if (++$completionDecisions > 64 || 16384 - $completionBytes < \Ineersa\AgentCore\Application\Tool\ToolCompletionNotificationRenderer::MIN_BYTES + \Ineersa\AgentCore\Application\Tool\ToolCompletionMailboxConsumer::PROVIDER_PREFIX_BYTES) {
                    if (!$completionDrainScheduled && 0 === $completionBytes && [] !== $eventSpecs && null === $state->parentRunId) {
                        $actions[] = \Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory::forCompletion($state, $this->completionEligibility->capture($state->runId), mailboxDrain: true);
                        $completionDrainScheduled = true;
                    }
                    // Presentation/decision bounds do not let internal rows
                    // hide later human controls. Unvisited deliveries stay pending.
                    continue;
                }
                $decision = $this->toolCompletions->apply($pendingCommand, $state->with(['messages' => $messages]), 16384 - $completionBytes);
                $messages = $decision->state->messages;
                $eventSpecs = [...$eventSpecs, ...$decision->eventSpecs];
                $actions = [...$actions, ...$decision->postCommitActions];
                $completionBytes += $decision->completionBytes;
                if (CommandApplicationBoundary::StopBoundary === $boundary && $decision->shouldContinue) {
                    $shouldContinue = true;
                }
                continue;
            }
            if (\in_array($pendingCommand->kind, [CoreCommandKind::Steer, CoreCommandKind::FollowUp, CoreCommandKind::AppendMessage], true)) {
                $messagePayload = $pendingCommand->payload['message'] ?? null;
                if (!\is_array($messagePayload)) {
                    $eventSpecs[] = $this->rejectCommand($state, $pendingCommand, 'Invalid command payload: missing message envelope.');

                    continue;
                }

                $hydratedMessage = AgentMessage::fromPayload($messagePayload);
                if (null === $hydratedMessage) {
                    $eventSpecs[] = $this->rejectCommand($state, $pendingCommand, 'Invalid command payload: malformed message envelope.');

                    continue;
                }

                $messages[] = $hydratedMessage;

                // Include serialized message payload so events.jsonl replay
                // can reconstruct user message transcript blocks.
                $messageArray = $hydratedMessage->toArray();
                $text = self::extractMessageText($messageArray);

                $eventSpecs[] = [
                    'type' => RunEventTypeEnum::AgentCommandApplied->value,
                    'payload' => [
                        'kind' => $pendingCommand->kind,
                        'idempotency_key' => $pendingCommand->idempotencyKey,
                        'message' => $messageArray,
                        'text' => $text,
                        'options' => [
                            'cancel_safe' => $pendingCommand->options->safe,
                        ],
                    ],
                ];

                if (CommandApplicationBoundary::StopBoundary === $boundary) {
                    $shouldContinue = true;
                }

                continue;
            }

            // Compact command: drain at safe boundary by dispatching a
            // CompactRun message.  The CompactRunHandler will prepare
            // and execute the compaction as an async step.  We do NOT set
            // shouldContinue because compaction is terminal — it does not
            // advance the turn.
            if (CoreCommandKind::Compact === $pendingCommand->kind) {
                $eventSpecs[] = [
                    'type' => RunEventTypeEnum::AgentCommandApplied->value,
                    'payload' => [
                        'kind' => $pendingCommand->kind,
                        'idempotency_key' => $pendingCommand->idempotencyKey,
                        'options' => [],
                    ],
                ];

                $customInstructions = \is_string($pendingCommand->payload['custom_instructions'] ?? null)
                    ? $pendingCommand->payload['custom_instructions']
                    : null;

                $stepId = \sprintf('compact-%d', hrtime(true));
                $effects[] = new CompactRun(
                    runId: $state->runId,
                    turnNo: $state->turnNo,
                    stepId: $stepId,
                    attempt: 1,
                    idempotencyKey: hash('sha256', \sprintf('%s|%s', $state->runId, $stepId)),
                    trigger: 'manual',
                    customInstructions: $customInstructions,
                );

                continue;
            }

            if (!CoreCommandKind::isCore($pendingCommand->kind)) {
                $eventSpecs = [
                    ...$eventSpecs,
                    ...$this->applyExtensionCommand($state, $pendingCommand),
                ];
            }
        }

        return new CommandApplicationResult(
            $state->with(['messages' => $messages]),
            $eventSpecs,
            $shouldContinue,
            $effects,
            $actions,
            $completionBytes,
        );
    }

    /**
     * Prepare a rejection event without changing the captured mailbox cutoff.
     * The owner journals its corresponding status decision before finalization.
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function rejectCommand(RunState $state, PendingCommand $command, string $reason): array
    {
        return [
            'type' => RunEventTypeEnum::AgentCommandRejected->value,
            'payload' => [
                'kind' => $command->kind,
                'idempotency_key' => $command->idempotencyKey,
                'reason' => $reason,
            ],
        ];
    }

    /**
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    private function applyExtensionCommand(RunState $state, PendingCommand $command): array
    {
        $handler = $this->commandRouter->handlerFor($command->kind);
        if (null === $handler) {
            return [$this->rejectCommand($state, $command, 'No extension command handler registered.')];
        }

        $cancellation = $command->options;

        try {
            $mappedObjects = $handler->map(
                $state->runId,
                $command->kind,
                $command->payload,
                $cancellation,
            );
        } catch (\Throwable $throwable) {
            return [$this->rejectCommand($state, $command, $throwable->getMessage())];
        }

        $eventSpecs = [[
            'type' => RunEventTypeEnum::AgentCommandApplied->value,
            'payload' => [
                'kind' => $command->kind,
                'idempotency_key' => $command->idempotencyKey,
                'options' => [
                    'cancel_safe' => $cancellation->safe,
                ],
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
     * Extract concatenated text content from an AgentMessage-like array.
     *
     * Text parts are joined with a newline, matching the other
     * content-part extraction paths (normalizer, tool-result transcript,
     * provider conversion) so multi-part messages render consistently in
     * canonical agent_command_applied payloads and transcript text.
     *
     * @param array<string, mixed> $messageArray
     */
    private static function extractMessageText(array $messageArray): string
    {
        $content = $messageArray['content'] ?? [];
        if (!\is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (\is_array($block) && isset($block['text']) && ('text' === ($block['type'] ?? null))) {
                $parts[] = (string) $block['text'];
            }
        }

        return implode("\n", $parts);
    }
}
