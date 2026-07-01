<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Config\FileRewindConfig;
use Ineersa\CodingAgent\Runtime\Contract\TreeFileRestoreOption;
use Ineersa\CodingAgent\Runtime\Contract\TreeFileRestoreProviderInterface;

final readonly class SessionTreeFileRestoreProvider implements TreeFileRestoreProviderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private FileRewindLedgerProjector $ledgerProjector,
        private FileRewindConfig $config,
        private FileRewindCheckpointService $checkpointService,
    ) {
    }

    public function optionsForTurn(string $sessionId, int $targetTurnNo): array
    {
        $options = [
            new TreeFileRestoreOption('keep_files', 'Keep current files', true),
            new TreeFileRestoreOption('cancel', 'Cancel navigation', true),
        ];

        if (!$this->checkpointService->isOperational()) {
            $options[] = new TreeFileRestoreOption(
                'restore_files',
                'Restore files to that point',
                false,
                'File rewind unavailable (git missing or disabled in settings).',
            );

            return $this->withUndo($sessionId, $options, []);
        }

        $events = $this->eventStore->allFor($sessionId);
        $byTurn = $this->ledgerProjector->checkpointsByTurn($events, $this->config->maxRetainedTurns);
        if (!isset($byTurn[$targetTurnNo]) || $byTurn[$targetTurnNo]->pruned) {
            $reason = $byTurn[$targetTurnNo]->unavailableReason ?? 'No file checkpoint for this turn.';
            $options[] = new TreeFileRestoreOption('restore_files', 'Restore files to that point', false, $reason);
        } else {
            $options[] = new TreeFileRestoreOption('restore_files', 'Restore files to that point', true);
        }

        return $this->withUndo($sessionId, $options, $events);
    }

    /**
     * @param list<\Ineersa\AgentCore\Domain\Event\RunEvent> $events
     * @param list<TreeFileRestoreOption>                    $options
     *
     * @return list<TreeFileRestoreOption>
     */
    private function withUndo(string $sessionId, array $options, array $events): array
    {
        $undo = $this->ledgerProjector->findUndoCheckpoint($events);
        if (null === $undo) {
            $options[] = new TreeFileRestoreOption(
                'undo_file_rewind',
                'Undo last file rewind',
                false,
                'No undo checkpoint yet.',
            );
        } else {
            $options[] = new TreeFileRestoreOption('undo_file_rewind', 'Undo last file rewind', true);
        }

        return $options;
    }
}
