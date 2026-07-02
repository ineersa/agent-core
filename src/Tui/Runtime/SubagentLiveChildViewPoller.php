<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Polls a selected child run id and projects readonly live transcript blocks.
 *
 * Readonly child transcript projection only (no child HITL in Phase 1).
 */
final class SubagentLiveChildViewPoller
{
    private const float POLL_INTERVAL = 0.05;

    private readonly TuiRuntimeEventApplier $eventApplier;

    public function __construct(
        private readonly TranscriptProjectorInterface $projector,
    ) {
        $this->eventApplier = new TuiRuntimeEventApplier($this->projector);
    }

    public function resetProjection(): void
    {
        $this->projector->reset();
    }

    /**
     * @return list<TranscriptBlock>|null null when no new child blocks (no screen repaint)
     */
    public function poll(
        SubagentLiveViewState $live,
        AgentSessionClient $client,
    ): ?array {
        if (!$live->active || null === $live->selected) {
            return null;
        }

        $now = microtime(true);
        if (($now - $live->childLastPoll) < self::POLL_INTERVAL) {
            return null;
        }
        $live->childLastPoll = $now;

        $events = $this->runtimeEvents($client, $live->selected->agentRunId);
        if ([] === $events) {
            return null;
        }

        $changed = false;
        $scratch = new TuiSessionState($live->selected->agentRunId);
        $scratch->activity = $live->childActivity;

        foreach ($events as $event) {
            $seq = $event->seq;
            if (0 !== $seq && $seq <= $live->childLastSeq) {
                continue;
            }
            if (0 !== $seq) {
                $live->childLastSeq = $seq;
            }

            $this->eventApplier->apply($scratch, $event);
            $changed = true;
        }

        if ($changed) {
            $live->childActivity = $scratch->activity;
        }

        if (!$changed) {
            return null;
        }

        $live->childTranscript = $this->projector->blocks();

        return $live->childTranscript;
    }

    /** @return list<RuntimeEvent> */
    private function runtimeEvents(AgentSessionClient $client, string $runId): array
    {
        $events = $client->events($runId);
        if ($events instanceof \Traversable) {
            return iterator_to_array($events, false);
        }

        return $events;
    }
}
