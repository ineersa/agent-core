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
use Ineersa\AgentCore\Domain\Run\RunStatus;

final readonly class CommandMailboxPolicy
{
    private const string SteerDrainOneAtATime = 'one_at_a_time';
    private const string SteerDrainAll = 'all';

    public function __construct(
        private CommandStoreInterface $commandStore,
        private CommandRouter $commandRouter,
        private string $steerDrainMode = self::SteerDrainOneAtATime,
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

    public function continueRejectionReason(RunState $state): ?string
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
        $pendingCommands = $this->commandStore->pending($state->runId);
        if ([] === $pendingCommands) {
            return new CommandApplicationResult($state, [], false);
        }

        $messages = $state->messages;
        $eventSpecs = [];
        $effects = [];
        $shouldContinue = false;
        $supersededSteerKeys = $this->supersededSteerKeys($pendingCommands);

        foreach ($pendingCommands as $pendingCommand) {
            if (isset($supersededSteerKeys[$pendingCommand->idempotencyKey])) {
                $this->commandStore->markSuperseded($state->runId, $pendingCommand->idempotencyKey, 'Superseded by a newer steer command.');
                $eventSpecs[] = [
                    'type' => RunEventTypeEnum::AgentCommandSuperseded->value,
                    'payload' => [
                        'kind' => CoreCommandKind::Steer,
                        'idempotency_key' => $pendingCommand->idempotencyKey,
                        'reason' => 'Superseded by a newer steer command.',
                    ],
                ];

                continue;
            }

            if (\in_array($pendingCommand->kind, [CoreCommandKind::Steer, CoreCommandKind::FollowUp, CoreCommandKind::AppendMessage], true)) {
                $messagePayload = $pendingCommand->payload['message'] ?? null;
                if (!\is_array($messagePayload)) {
                    $this->commandStore->markRejected($state->runId, $pendingCommand->idempotencyKey, 'Invalid command payload: missing message envelope.');
                    $eventSpecs[] = [
                        'type' => RunEventTypeEnum::AgentCommandRejected->value,
                        'payload' => [
                            'kind' => $pendingCommand->kind,
                            'idempotency_key' => $pendingCommand->idempotencyKey,
                            'reason' => 'Invalid command payload: missing message envelope.',
                        ],
                    ];

                    continue;
                }

                $hydratedMessage = AgentMessage::fromPayload($messagePayload);
                if (null === $hydratedMessage) {
                    $this->commandStore->markRejected($state->runId, $pendingCommand->idempotencyKey, 'Invalid command payload: malformed message envelope.');
                    $eventSpecs[] = [
                        'type' => RunEventTypeEnum::AgentCommandRejected->value,
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
            $this->copyState($state, ['messages' => $messages]),
            $eventSpecs,
            $shouldContinue,
            $effects,
        );
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
                'type' => RunEventTypeEnum::AgentCommandRejected->value,
                'payload' => [
                    'kind' => $command->kind,
                    'idempotency_key' => $command->idempotencyKey,
                    'reason' => 'No extension command handler registered.',
                ],
            ]];
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
            $this->commandStore->markRejected($state->runId, $command->idempotencyKey, $throwable->getMessage());

            return [[
                'type' => RunEventTypeEnum::AgentCommandRejected->value,
                'payload' => [
                    'kind' => $command->kind,
                    'idempotency_key' => $command->idempotencyKey,
                    'reason' => $throwable->getMessage(),
                ],
            ]];
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

    private function lastMessageRole(RunState $state): ?string
    {
        if ([] === $state->messages) {
            return null;
        }

        $lastMessage = $state->messages[\count($state->messages) - 1] ?? null;

        return $lastMessage instanceof AgentMessage ? $lastMessage->role : null;
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
            retryAttempts: $overrides['retryAttempts'] ?? $state->retryAttempts,
            pendingHumanInputRequests: $overrides['pendingHumanInputRequests'] ?? $state->pendingHumanInputRequests,
        );
    }

    /**
     * Extract concatenated text content from an AgentMessage-like array.
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

        return implode('', $parts);
    }
}
