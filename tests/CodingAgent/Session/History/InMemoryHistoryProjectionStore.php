<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Contract\History\AppliedShellCommandLookupInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\History\HistoryDTO;
use Ineersa\CodingAgent\Session\History\HistoryProjectionSnapshot;
use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistoryStreamBuilder;

/**
 * Process-local history projection store for unit tests.
 */
final class InMemoryHistoryProjectionStore implements HistoryProjectionStoreInterface, AppliedShellCommandLookupInterface
{
    public int $getCalls = 0;

    public int $initializeFromEventsCalls = 0;

    /** @var array<string, HistoryProjectionSnapshot> */
    private array $snapshots = [];

    public function __construct(
        private readonly HistoryProjector $projector = new HistoryProjector(),
    ) {
    }

    /**
     * @param list<RunEvent> $events
     *
     * @deprecated use {@see initializeFromEvents()}
     */
    public function seedFromEvents(string $runId, array $events): HistoryProjectionSnapshot
    {
        return $this->initializeFromEvents($runId, $events);
    }

    public function get(string $runId): HistoryProjectionSnapshot
    {
        ++$this->getCalls;
        if (!isset($this->snapshots[$runId])) {
            throw new \RuntimeException(\sprintf('History projection missing for run %s; initialize via startup/recovery before ordinary lookups.', $runId));
        }

        $snapshot = $this->snapshots[$runId];
        if (!$snapshot->ready) {
            throw new \RuntimeException(\sprintf('History projection for run %s is not ready; recovery required.', $runId));
        }

        return $snapshot;
    }

    public function find(string $runId): ?HistoryProjectionSnapshot
    {
        return $this->snapshots[$runId] ?? null;
    }

    public function remember(string $runId, HistoryProjectionSnapshot $snapshot): void
    {
        $this->snapshots[$runId] = $snapshot->withReady(true);
    }

    public function hasAppliedShellCommand(string $runId, string $idempotencyKey): bool
    {
        return isset($this->get($runId)->appliedShellIdempotencyKeys[$idempotencyKey]);
    }

    public function withdrawForCommit(string $runId): void
    {
        if (!isset($this->snapshots[$runId])) {
            return;
        }

        $this->snapshots[$runId] = $this->snapshots[$runId]->withReady(false);
    }

    public function initializeFromEvents(string $runId, iterable $events): HistoryProjectionSnapshot
    {
        ++$this->initializeFromEventsCalls;
        $events = \is_array($events) ? $events : iterator_to_array($events, false);
        if ([] === $events) {
            $snapshot = new HistoryProjectionSnapshot(
                history: new HistoryDTO(retainedTurnNos: [], promptsByTurnNo: [], positionTurnNo: 0),
                lastSeq: 0,
                ready: true,
            );
            $this->snapshots[$runId] = $snapshot;

            return $snapshot;
        }

        $sorted = $events;
        usort($sorted, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        $builder = $this->projector->createStreamBuilder();
        $seen = [];
        $maxSeq = 0;
        foreach ($sorted as $event) {
            if (isset($seen[$event->seq])) {
                throw new \RuntimeException(\sprintf('Cannot reconstruct history for run %s: duplicate sequence %d.', $runId, $event->seq));
            }
            $seen[$event->seq] = true;
            $builder->apply($event);
            $maxSeq = max($maxSeq, $event->seq);
        }

        $snapshot = $builder->finishSnapshot($maxSeq);
        $ready = $snapshot->withReady(true);
        $this->snapshots[$runId] = $ready;

        return $ready;
    }

    public function applyCommitted(string $runId, array $events): void
    {
        if ([] === $events) {
            return;
        }

        $sorted = $events;
        usort($sorted, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);
        $maxSeq = $sorted[array_key_last($sorted)]->seq;
        $cached = $this->snapshots[$runId] ?? null;

        if (null === $cached) {
            $hasRunStarted = false;
            foreach ($sorted as $event) {
                if (RunEventTypeEnum::RunStarted->value === $event->type) {
                    $hasRunStarted = true;
                    break;
                }
            }
            if (!$hasRunStarted) {
                throw new \RuntimeException(\sprintf('History projection missing for run %s while applying committed seq %d; recovery required.', $runId, $maxSeq));
            }

            $builder = $this->projector->createStreamBuilder();
            $seen = [];
            foreach ($sorted as $event) {
                if (isset($seen[$event->seq])) {
                    throw new \RuntimeException(\sprintf('Cannot bootstrap history for run %s: duplicate sequence %d.', $runId, $event->seq));
                }
                $seen[$event->seq] = true;
                $builder->apply($event);
            }
            $this->snapshots[$runId] = $builder->finishSnapshot($maxSeq);
            $this->snapshots[$runId] = $this->snapshots[$runId]->withReady(true);

            return;
        }

        if ($cached->lastSeq >= $maxSeq) {
            return;
        }

        $builder = HistoryStreamBuilder::fromSnapshot($cached);
        $expectedSeq = $cached->lastSeq + 1;
        $applied = false;
        foreach ($sorted as $event) {
            if ($event->seq <= $cached->lastSeq) {
                continue;
            }
            if ($event->seq !== $expectedSeq) {
                throw new \RuntimeException(\sprintf('History projection for run %s is inconsistent at seq %d (expected %d); recovery required.', $runId, $event->seq, $expectedSeq));
            }
            $builder->apply($event);
            ++$expectedSeq;
            $applied = true;
        }
        if (!$applied) {
            return;
        }
        $this->snapshots[$runId] = $builder->finishSnapshot($maxSeq)->withReady(true);
    }
}
