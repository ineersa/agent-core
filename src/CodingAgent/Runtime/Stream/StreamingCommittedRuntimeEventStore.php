<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;

final class StreamingCommittedRuntimeEventStore implements \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface
{
    /** @var array<string, list<RunEvent>> */
    private array $pendingEvents = [];

    public function __construct(
        private readonly EventStoreInterface $inner,
        private readonly RuntimeEventMapper $mapper,
        private readonly RuntimeEventSinkInterface $stdoutSink,
        private readonly bool $streamCommittedEventsToStdout,
    ) {
    }

    public function appendTransition(array $events, array $work): array
    {
        if (!$this->inner instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }
        $persisted = $this->inner->appendTransition($events, $work);
        $runId = $events[0]->runId ?? $work['run_id'] ?? null;
        if (!\is_string($runId) || '' === $runId) {
            throw new \InvalidArgumentException('Prepared transition requires run identity.');
        }
        // Retain only the already-persisted hot batch until verified finalization.
        // Normal commits must not recover these events through archive reads.
        $this->pendingEvents[$runId] = $persisted;

        return $persisted;
    }

    public function verifiedPendingTransition(string $runId): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO
    {
        $store = $this->inner;
        if (!$store instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }

        return $store->verifiedPendingTransition($runId);
    }

    public function verifiedPendingBatch(string $runId, string $identity): array
    {
        $store = $this->inner;
        if (!$store instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }

        return $store->verifiedPendingBatch($runId, $identity);
    }

    public function finalizeVerifiedTransition(string $runId, string $identity): void
    {
        $store = $this->inner;
        if (!$store instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }
        $pending = $store->verifiedPendingTransition($runId);
        if (null === $pending) {
            throw new \RuntimeException('Verified transition missing before stream publication.');
        }
        if ($pending->identity !== $identity) {
            throw new \RuntimeException('Prepared transition identity changed before stream publication.');
        }

        $hotBatch = $this->pendingEvents[$runId] ?? null;
        if (null === $hotBatch) {
            // Cold recovery uses owner-only staged bytes. Public range readers still honor the cut.
            $hotBatch = $store->verifiedPendingBatch($runId, $identity);
        }
        $this->assertVerifiedBatch($pending, $hotBatch);

        $store->finalizeVerifiedTransition($runId, $identity);
        unset($this->pendingEvents[$runId]);
        foreach ($hotBatch as $event) {
            $this->emitMapped($event);
        }
    }

    public function assertTransitionReady(string $runId): void
    {
        if (!$this->inner instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }
        $this->inner->assertTransitionReady($runId);
    }

    public function latestSequenceFor(string $runId): ?int
    {
        return $this->inner->latestSequenceFor($runId);
    }

    public function firstFor(string $runId): ?RunEvent
    {
        return $this->inner->firstFor($runId);
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        return $this->inner->rangeFor($runId, $startSeq, $endSeq);
    }

    public function reverseFor(string $runId): iterable
    {
        return $this->inner->reverseFor($runId);
    }

    public function allFor(string $runId): array
    {
        return $this->inner->allFor($runId);
    }

    /** @param list<RunEvent> $batch */
    private function assertVerifiedBatch(
        \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $pending,
        array $batch,
    ): void {
        $sequences = array_map(static fn (RunEvent $event): int => $event->seq, $batch);
        if ($sequences !== $pending->eventSequences) {
            throw new \RuntimeException('Verified transition batch sequences do not match captured identities.');
        }
        foreach ($batch as $event) {
            if (($pending->work['run_id'] ?? null) !== $event->runId) {
                throw new \RuntimeException('Verified transition batch run identity mismatch.');
            }
        }
    }

    private function emitMapped(RunEvent $runEvent): void
    {
        if (!$this->streamCommittedEventsToStdout) {
            return;
        }

        $runtimeEvent = $this->mapper->toRuntimeEvent($runEvent);
        if (null === $runtimeEvent) {
            return;
        }

        $this->stdoutSink->emit($runtimeEvent);
    }
}
