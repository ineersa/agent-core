<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Session\RunHistoryIndex;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Psr\Log\LoggerInterface;

/**
 * Positions linear history for /history selection.
 *
 * Selecting user prompt turn N is non-destructive:
 *  - Appends history_position_set with position = predecessor(N) (or 0 for first)
 *  - Rebuilds RunState for that boundary (context immediately before N)
 *  - Returns selected_prompt_turn_no = N for the editor
 *  - Forward turns remain active until a context-mutating action discards them
 *
 * Selecting a final answer keeps its turn and prefills the next retained human
 * prompt, or clears the editor when there is none. User predecessors and next
 * prompts follow immutable retained anchor order, including hidden internal turns.
 */
final readonly class HistorySelectionService implements HistorySelectionServiceInterface
{
    public function __construct(
        private SessionRunEventStore $eventStore,
        private RunStateRebuilderInterface $runStateRebuilder,
        private ActiveRunContextInterface $activeRunContext,
        private RunLockManager $lockManager,
        private LoggerInterface $logger,
        private RunHistoryIndex $historyIndex,
        private RunCommit $runCommit,
    ) {
    }

    /**
     * @return array{rebuiltState: RunState, positionEventSeq: int, selectedPromptTurnNo: int, editorPromptText: string}
     *
     * @throws RunStateDuplicateSequenceReplayException
     * @throws \RuntimeException
     */
    public function selectEntry(string $runId, int $targetTurnNo, string $commandId, string $role = 'user'): array
    {
        return $this->lockManager->synchronized($runId, function () use ($runId, $targetTurnNo, $role): array {
            $this->runCommit->assertTransitionReady($runId);
            $source = $this->eventStore->historySource($runId);
            $prompt = $this->historyIndex->selectEntry($source->log, $source->path, $runId, $targetTurnNo, $role);
            $state = $this->activeRunContext->requireLoaded($runId);
            $previousPosition = $prompt['position'];
            $positionTurnNo = $prompt['predecessor'];
            $editorPromptText = $prompt['text'];

            $positionEvent = new RunEvent(
                runId: $runId,
                seq: 0,
                turnNo: $positionTurnNo,
                type: RunEventTypeEnum::HistoryPositionSet->value,
                payload: [
                    'position_turn_no' => $positionTurnNo,
                    'previous_position_turn_no' => $previousPosition,
                    'selected_prompt_turn_no' => $prompt['selectedPromptTurnNo'],
                    'reason' => 'history_select',
                ],
                createdAt: new \DateTimeImmutable(),
            );

            $replayResult = $this->runStateRebuilder->rebuildAtPosition($state, $runId, $positionTurnNo);
            if (null === $replayResult->rebuiltState) {
                throw new \RuntimeException(\sprintf('Failed to rebuild state for run %s at position %d.', $runId, $positionTurnNo));
            }

            $rebuiltState = $replayResult->rebuiltState;
            $rebuiltState = $rebuiltState->with(['turnNo' => $positionTurnNo, 'version' => $state->version]);
            $rebuiltState = $this->runCommit->commit($state, $rebuiltState, [$positionEvent], dispatchAfterTurnHooks: false);
            $newSeq = $rebuiltState->lastSeq;

            $this->logger->info('run_history.selected', [
                'run_id' => $runId,
                'selected_prompt_turn_no' => $prompt['selectedPromptTurnNo'],
                'selected_entry_turn_no' => $targetTurnNo,
                'selected_entry_role' => $role,
                'position_turn_no' => $positionTurnNo,
                'previous_position_turn_no' => $previousPosition,
                'position_event_seq' => $newSeq,
                'component' => 'history',
                'session_id' => $runId,
                'event_type' => 'history_position_set',
            ]);

            return [
                'rebuiltState' => $rebuiltState,
                'positionEventSeq' => $newSeq,
                'selectedPromptTurnNo' => $prompt['selectedPromptTurnNo'],
                'editorPromptText' => $editorPromptText,
            ];
        });
    }
}
