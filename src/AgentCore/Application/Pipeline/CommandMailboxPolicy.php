<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Contract\CommandConsumptionPolicyInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Compaction\CompactionTriggerEnum;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class CommandMailboxPolicy
{
    /** @param iterable<CommandConsumptionPolicyInterface> $consumptionPolicies */
    public function __construct(
        private CommandStoreInterface $commandStore,
        #[AutowireIterator('agent_core.command_consumption_policy')]
        private iterable $consumptionPolicies = [],
    ) {
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
        if ([] === $pendingCommands) {
            return new CommandApplicationResult($state, [], false);
        }

        $messages = $state->messages;
        $eventSpecs = [];
        $effects = [];
        $shouldContinue = false;
        $hasDeferred = false;

        foreach ($pendingCommands as $pendingCommand) {
            $metadata = [];
            $retirementReason = null;
            $defer = false;
            foreach ($this->consumptionPolicies as $policy) {
                $decision = $policy->prepare($state, $pendingCommand);
                if (null !== $decision) {
                    $metadata = array_replace($metadata, $decision->metadata);
                    $retirementReason ??= $decision->retirementReason;
                    $defer = $defer || $decision->defer;
                }
            }
            if (null !== $retirementReason) {
                $event = $this->rejectCommand($pendingCommand, $retirementReason);
                $event['payload']['consumption_metadata'] = $metadata;
                $eventSpecs[] = $event;

                continue;
            }
            // A deferred decision leaves the row and its original continuation
            // untouched. Only an applied or rejected event settles the mailbox.
            if ($defer) {
                $hasDeferred = true;
                continue;
            }
            if (\in_array($pendingCommand->kind, [CoreCommandKind::Steer, CoreCommandKind::FollowUp, CoreCommandKind::AppendMessage], true)) {
                $messagePayload = $pendingCommand->payload['message'] ?? null;
                if (!\is_array($messagePayload)) {
                    $eventSpecs[] = $this->rejectCommand($pendingCommand, 'Invalid command payload: missing message envelope.');

                    continue;
                }

                $hydratedMessage = AgentMessage::fromPayload($messagePayload);
                if (null === $hydratedMessage) {
                    $eventSpecs[] = $this->rejectCommand($pendingCommand, 'Invalid command payload: malformed message envelope.');

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
                        ...([] === $metadata ? [] : ['consumption_metadata' => $metadata]),
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
                    trigger: CompactionTriggerEnum::Manual->value,
                    customInstructions: $customInstructions,
                );

                continue;
            }

            if (!CoreCommandKind::isCore($pendingCommand->kind)) {
                $eventSpecs[] = $this->rejectCommand($pendingCommand, \sprintf('Unknown command kind "%s".', $pendingCommand->kind));
            }
        }

        return new CommandApplicationResult(
            $state->with(['messages' => $messages]),
            $eventSpecs,
            $shouldContinue,
            $effects,
            $hasDeferred,
        );
    }

    /**
     * Prepare a rejection event without changing the captured mailbox cutoff.
     * The owner journals its corresponding status decision before finalization.
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function rejectCommand(PendingCommand $command, string $reason): array
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
