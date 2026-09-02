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
        if ([] === $pendingCommands) {
            return new CommandApplicationResult($state, [], false);
        }

        $messages = $state->messages;
        $eventSpecs = [];
        $effects = [];
        $shouldContinue = false;

        foreach ($pendingCommands as $pendingCommand) {
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
                $this->commandStore->markApplied($state->runId, $pendingCommand->idempotencyKey);

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
                $this->commandStore->markApplied($state->runId, $pendingCommand->idempotencyKey);

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
        );
    }

    /**
     * Reject a pending command in the store and produce its rejection event spec.
     *
     * markRejected runs before the event is built so a failed store write
     * aborts before any rejection event is emitted.
     *
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function rejectCommand(RunState $state, PendingCommand $command, string $reason): array
    {
        $this->commandStore->markRejected($state->runId, $command->idempotencyKey, $reason);

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

        $this->commandStore->markApplied($state->runId, $command->idempotencyKey);

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
