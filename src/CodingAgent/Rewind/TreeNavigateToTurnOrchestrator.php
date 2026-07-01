<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

/**
 * Synchronous /tree navigation: file choice then optional conversation rewind.
 */
final class TreeNavigateToTurnOrchestrator
{
    public function __construct(
        private readonly FileRewindCheckpointService $checkpointService,
        private readonly ConversationRewindInterface $rewindService,
    ) {
    }

    /**
     * @return array{leaf_set_seq: int, turn_no: int}|null null when undo/cancel (no leaf change)
     */
    public function execute(string $runId, int $targetTurnNo, TreeFileRestoreChoiceEnum $choice): ?array
    {
        return match ($choice) {
            TreeFileRestoreChoiceEnum::Cancel => null,
            TreeFileRestoreChoiceEnum::UndoFileRewind => $this->executeUndo($runId),
            TreeFileRestoreChoiceEnum::RestoreFiles => $this->executeRestoreThenRewind($runId, $targetTurnNo),
            TreeFileRestoreChoiceEnum::KeepFiles => $this->executeRewindOnly($runId, $targetTurnNo),
        };
    }

    private function executeUndo(string $runId): null
    {
        $this->checkpointService->undoLastRestore($runId);

        return null;
    }

    /** @return array{leaf_set_seq: int, turn_no: int} */
    private function executeRestoreThenRewind(string $runId, int $targetTurnNo): array
    {
        $this->checkpointService->restoreForTurn($runId, $targetTurnNo);

        return $this->executeRewindOnly($runId, $targetTurnNo);
    }

    /** @return array{leaf_set_seq: int, turn_no: int} */
    private function executeRewindOnly(string $runId, int $targetTurnNo): array
    {
        $result = $this->rewindService->rewind($runId, $targetTurnNo);

        return [
            'leaf_set_seq' => $result['leafSetSeq'],
            'turn_no' => $targetTurnNo,
        ];
    }
}
