<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Boundary;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\ConversationBoundaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\ConversationBoundaryOutcomeEnum;

/**
 * Stateless projector that derives post-commit conversation boundaries from
 * canonical event history and the just-committed batch.
 *
 * MVP range rule:
 * - Terminal marker is agent_end with reason completed|failed|cancelled in the
 *   just-committed batch (or a direct permanent-failure append of that event).
 * - sourceEndSeq is the terminal agent_end seq.
 * - sourceStartSeq is the first seq after the previous terminal agent_end in
 *   canonical history, or 1 when none exists.
 * - Events after agent_end in the same batch do not extend the boundary range;
 *   latestCommittedSeq still reflects the batch watermark.
 * - Intermediate/retryable commits without a terminal agent_end emit nothing.
 * - Repair/rewind metadata events alone never create boundaries.
 *
 * @internal app-layer adapter; not part of ExtensionApi
 */
final readonly class ConversationBoundaryProjector
{
    public function __construct(
        private EventStoreInterface $eventStore,
    ) {
    }

    /**
     * @param list<RunEvent> $persistedEvents
     */
    public function projectFromPersistedBatch(string $runId, array $persistedEvents): ?ConversationBoundaryDTO
    {
        if ([] === $persistedEvents) {
            return null;
        }

        $terminal = $this->findTerminalAgentEnd($persistedEvents);
        if (null === $terminal) {
            return null;
        }

        $outcome = $this->outcomeFromAgentEnd($terminal);
        if (null === $outcome) {
            return null;
        }

        $latestCommittedSeq = $persistedEvents[array_key_last($persistedEvents)]->seq;
        $sourceEndSeq = $terminal->seq;
        $sourceStartSeq = $this->resolveSourceStartSeq($runId, $sourceEndSeq);

        return new ConversationBoundaryDTO(
            runId: $runId,
            sessionId: $runId,
            boundaryId: $this->boundaryId($runId, $sourceEndSeq, $outcome),
            outcome: $outcome,
            sourceStartSeq: $sourceStartSeq,
            sourceEndSeq: $sourceEndSeq,
            latestCommittedSeq: max($latestCommittedSeq, $sourceEndSeq),
            boundaryAt: $terminal->createdAt,
            metadata: [
                'terminal_event_type' => $terminal->type,
                'terminal_reason' => (string) ($terminal->payload['reason'] ?? $outcome->value),
                'turn_no' => $terminal->turnNo,
            ],
        );
    }

    /**
     * @param list<RunEvent> $events
     */
    private function findTerminalAgentEnd(array $events): ?RunEvent
    {
        $terminal = null;
        foreach ($events as $event) {
            if (RunEventTypeEnum::AgentEnd->value !== $event->type) {
                continue;
            }
            if (null === $this->outcomeFromAgentEnd($event)) {
                continue;
            }
            $terminal = $event;
        }

        return $terminal;
    }

    private function outcomeFromAgentEnd(RunEvent $event): ?ConversationBoundaryOutcomeEnum
    {
        $reason = $event->payload['reason'] ?? null;
        if (!\is_string($reason) || '' === $reason) {
            return null;
        }

        return match ($reason) {
            'completed' => ConversationBoundaryOutcomeEnum::Completed,
            'failed' => ConversationBoundaryOutcomeEnum::Failed,
            'cancelled' => ConversationBoundaryOutcomeEnum::Cancelled,
            default => null,
        };
    }

    private function resolveSourceStartSeq(string $runId, int $sourceEndSeq): int
    {
        // Propagate event-store failures so ConversationBoundaryNotifier can
        // isolate/log them and skip delivering an inaccurate boundary DTO.
        $history = $this->eventStore->allFor($runId);

        $previousTerminalSeq = 0;
        foreach ($history as $event) {
            if ($event->seq >= $sourceEndSeq) {
                break;
            }
            if (RunEventTypeEnum::AgentEnd->value !== $event->type) {
                continue;
            }
            if (null === $this->outcomeFromAgentEnd($event)) {
                continue;
            }
            $previousTerminalSeq = $event->seq;
        }

        return max(1, $previousTerminalSeq + 1);
    }

    private function boundaryId(
        string $runId,
        int $sourceEndSeq,
        ConversationBoundaryOutcomeEnum $outcome,
    ): string {
        return hash('sha256', $runId.'|'.$sourceEndSeq.'|'.$outcome->value);
    }
}
