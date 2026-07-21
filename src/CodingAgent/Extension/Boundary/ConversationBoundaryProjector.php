<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Boundary;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\ConversationBoundaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\ConversationBoundaryOutcomeEnum;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;

/**
 * Stateless projector that derives post-commit conversation boundaries from
 * the just-persisted event batch only (no events.jsonl history scan).
 *
 * MVP rule:
 * - Terminal marker is agent_end with reason completed|failed|cancelled in the
 *   just-committed batch (or a direct permanent-failure append of that event).
 * - sourceEndSeq is the terminal agent_end seq.
 * - latestCommittedSeq is the highest seq in the batch.
 * - Events after agent_end in the same batch do not change sourceEndSeq.
 * - Intermediate/retryable commits without a terminal agent_end emit nothing.
 * - The extension owns its previous cursor; Hatfield does not invent sourceStartSeq.
 *
 * @internal app-layer adapter; not part of ExtensionApi
 */
final readonly class ConversationBoundaryProjector
{
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

        return new ConversationBoundaryDTO(
            runId: $runId,
            sessionId: $runId,
            boundaryId: $this->boundaryId($runId, $sourceEndSeq, $outcome),
            outcome: $outcome,
            sourceEndSeq: $sourceEndSeq,
            latestCommittedSeq: max($latestCommittedSeq, $sourceEndSeq),
            boundaryAt: $terminal->createdAt,
            events: $this->toSessionEvents($persistedEvents),
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

    /**
     * @param list<RunEvent> $events
     *
     * @return list<SessionEventDTO>
     */
    private function toSessionEvents(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $out[] = new SessionEventDTO(
                runId: $event->runId,
                seq: $event->seq,
                turnNo: $event->turnNo,
                type: $event->type,
                payload: $event->payload,
                createdAt: $event->createdAt,
            );
        }

        return $out;
    }

    private function boundaryId(
        string $runId,
        int $sourceEndSeq,
        ConversationBoundaryOutcomeEnum $outcome,
    ): string {
        return hash('sha256', $runId.'|'.$sourceEndSeq.'|'.$outcome->value);
    }
}
