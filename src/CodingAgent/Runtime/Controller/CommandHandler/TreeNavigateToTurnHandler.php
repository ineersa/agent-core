<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Rewind\TreeFileRestoreChoiceEnum;
use Ineersa\CodingAgent\Rewind\TreeNavigateToTurnOrchestrator;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class TreeNavigateToTurnHandler
{
    public function __construct(
        private TreeNavigateToTurnOrchestrator $orchestrator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('tree_navigate_to_turn' !== $event->command->type) {
            return;
        }

        $runId = $event->command->runId ?? '';
        if ('' === $runId) {
            $event->emit($this->protocolError($runId, 'tree_navigate_to_turn requires runId'));

            return;
        }

        $targetTurnNo = $this->resolveTurnNo($event->command->payload);
        if (null === $targetTurnNo) {
            $event->emit($this->protocolError($runId, 'tree_navigate_to_turn requires turn_no in payload'));

            return;
        }

        $choice = TreeFileRestoreChoiceEnum::tryFromPayload($event->command->payload['file_choice'] ?? null);
        if (null === $choice) {
            $event->emit($this->protocolError($runId, 'tree_navigate_to_turn requires valid file_choice'));

            return;
        }

        try {
            $leafChange = $this->orchestrator->execute($runId, $targetTurnNo, $choice);
            if (TreeFileRestoreChoiceEnum::Cancel === $choice) {
                $event->emit(new RuntimeEvent(
                    type: RuntimeEventTypeEnum::StatusUpdated->value,
                    runId: $runId,
                    seq: 0,
                    payload: ['status' => 'tree_navigate_cancelled'],
                ));

                return;
            }
            if (TreeFileRestoreChoiceEnum::UndoFileRewind === $choice) {
                $event->emit(new RuntimeEvent(
                    type: RuntimeEventTypeEnum::StatusUpdated->value,
                    runId: $runId,
                    seq: 0,
                    payload: ['status' => 'file_rewind_undo_ok'],
                ));

                return;
            }
            if (null !== $leafChange) {
                $event->emit(new RuntimeEvent(
                    type: RuntimeEventTypeEnum::RunLeafChanged->value,
                    runId: $runId,
                    seq: $leafChange['leaf_set_seq'],
                    payload: [
                        'turn_no' => $leafChange['turn_no'],
                        'leaf_set_seq' => $leafChange['leaf_set_seq'],
                    ],
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->error('tree_navigate_handler.failed', [
                'run_id' => $runId,
                'turn_no' => $targetTurnNo,
                'file_choice' => $choice->value,
                'error' => $e->getMessage(),
            ]);
            $event->emit($this->protocolError($runId, $e->getMessage()));
        }
    }

    /** @param array<string, mixed> $payload */
    private function resolveTurnNo(array $payload): ?int
    {
        $turnNo = $payload['turn_no'] ?? null;
        if (!\is_int($turnNo) || $turnNo < 1) {
            return null;
        }

        return $turnNo;
    }

    private function protocolError(string $runId, string $message): RuntimeEvent
    {
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::ProtocolError->value,
            runId: $runId,
            seq: 0,
            payload: ['error' => $message],
        );
    }
}
