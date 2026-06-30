<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Application\Handler\RunRewindService;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles rewind_to_turn JSONL commands from the parent TUI process.
 *
 * When the TUI user selects a turn in the /tree picker, the parent sends a
 * rewind_to_turn JSONL command with the target turn number. This handler:
 *  - Validates the command
 *  - Delegates to RunRewindService (acquires lock, appends LeafSet, rebuilds, persists)
 *  - Emits a RunLeafChanged RuntimeEvent so the TUI observes the leaf change
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class RewindToTurnHandler
{
    public function __construct(
        private RunRewindService $rewindService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('rewind_to_turn' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $runId = $command->runId ?? '';

        if ('' === $runId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => 'rewind_to_turn requires runId'],
            ));

            return;
        }

        $targetTurnNo = $this->resolveTargetTurnNo($command->payload);

        if (null === $targetTurnNo) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $runId,
                seq: 0,
                payload: ['error' => 'rewind_to_turn requires turn_no in payload'],
            ));

            return;
        }

        try {
            // Perform the rewind (lock + append LeafSet + rebuild + persist).
            $result = $this->rewindService->rewind($runId, $targetTurnNo);

            /** @var \Ineersa\AgentCore\Domain\Run\RunState $rebuiltState */
            $rebuiltState = $result['rebuiltState'];
            $leafSetSeq = $result['leafSetSeq'];

            // Emit RunLeafChanged RuntimeEvent so the TUI observes the leaf change.
            // The TUI can fetch the full tree via TurnTreeProviderInterface for richer info
            // when rebuilding transcript blocks after the leaf change.
            //
            // NOTE: InProcessAgentSessionClient::handleInProcessRewind() mirrors this
            // emission; both must emit an equivalent RunLeafChanged event so the TUI
            // rebuilds its transcript regardless of transport. Keep them in sync.
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::RunLeafChanged->value,
                runId: $runId,
                seq: $leafSetSeq,
                payload: [
                    'turn_no' => $targetTurnNo,
                    'leaf_set_seq' => $leafSetSeq,
                ],
            ));

            $this->logger->info('rewind_handler.completed', [
                'run_id' => $runId,
                'target_turn_no' => $targetTurnNo,
                'leaf_set_seq' => $leafSetSeq,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('rewind_handler.failed', [
                'run_id' => $runId,
                'target_turn_no' => $targetTurnNo,
                'exception' => $e->getMessage(),
            ]);

            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $runId,
                seq: 0,
                payload: ['error' => \sprintf('Rewind failed: %s', $e->getMessage())],
            ));
        }
    }

    /**
     * Extract and validate the target turn number from the command payload.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveTargetTurnNo(array $payload): ?int
    {
        $turnNo = $payload['turn_no'] ?? null;

        if (!\is_int($turnNo) || $turnNo < 1) {
            return null;
        }

        return $turnNo;
    }
}
