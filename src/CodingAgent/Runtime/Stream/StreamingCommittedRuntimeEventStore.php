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

    public function append(RunEvent $event): RunEvent
    {
        $persisted = $this->inner->append($event);

        return $persisted;
    }

    public function appendMany(array $events): array
    {
        $persisted = $this->inner->appendMany($events);

        return $persisted;
    }

    public function appendTransition(array $events, array $work): array
    {
        if (!$this->inner instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }
        $persisted = $this->inner->appendTransition($events, $work);
        $this->pendingEvents[$events[0]->runId ?? $work['run_id']] = $persisted;

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
        $start = $pending->work['predecessor_seq'] ?? null;
        if (!\is_int($start)) {
            throw new \RuntimeException('Verified transition has no predecessor sequence.');
        }
        $store->finalizeVerifiedTransition($runId, $identity);
        unset($this->pendingEvents[$runId]);
        foreach ($store->rangeFor($runId, $start + 1, $store->latestSequenceFor($runId) ?? $start) as $event) {
            $this->emitMapped($event);
        }
    }

    public function finalizeTransition(string $runId): void
    {
        if (!$this->inner instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }
        $this->inner->finalizeTransition($runId);
        $events = $this->pendingEvents[$runId] ?? [];
        unset($this->pendingEvents[$runId]);
        foreach ($events as $event) {
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
