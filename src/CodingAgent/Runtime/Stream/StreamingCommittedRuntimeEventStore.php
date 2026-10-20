<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
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
        private readonly \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher,
        private readonly ?RunTracer $tracer = null,
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

    public function verifiedPendingTransition(string $runId): ?VerifiedTransitionDTO
    {
        $store = $this->inner;
        if (!$store instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }

        return $store->verifiedPendingTransition($runId);
    }

    public function verifiedPendingBatch(string $runId, VerifiedTransitionDTO $verified): array
    {
        $store = $this->inner;
        if (!$store instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }

        return $store->verifiedPendingBatch($runId, $verified);
    }

    public function assertVerifiedTransition(string $runId, VerifiedTransitionDTO $verified): void
    {
        if (!$this->inner instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }
        $this->inner->assertVerifiedTransition($runId, $verified);
    }

    public function finalizeVerifiedTransition(string $runId, VerifiedTransitionDTO $verified): void
    {
        $store = $this->inner;
        if (!$store instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }

        $hotBatch = $this->pendingEvents[$runId] ?? null;
        if (null === $hotBatch) {
            // Cold recovery uses owner-only staged bytes. Public range readers still honor the cut.
            $hotBatch = $store->verifiedPendingBatch($runId, $verified);
        }
        $this->assertVerifiedBatch($verified, $hotBatch);

        $this->probe('canonical_finalize', $runId, static fn () => $store->finalizeVerifiedTransition($runId, $verified));
        unset($this->pendingEvents[$runId]);
        foreach ($hotBatch as $event) {
            $this->probe('listeners', $runId, fn () => $this->dispatcher->dispatch(new \Ineersa\CodingAgent\Session\Event\RunEventPublishedEvent($event)));
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
        VerifiedTransitionDTO $pending,
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

        $runtimeEvent = $this->probe('map', $runEvent->runId, fn () => $this->mapper->toRuntimeEvent($runEvent));
        if (null === $runtimeEvent) {
            return;
        }

        $this->probe('stdout', $runEvent->runId, fn () => $this->stdoutSink->emit($runtimeEvent));
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function probe(string $phase, string $runId, callable $operation): mixed
    {
        return null === $this->tracer ? $operation() : $this->tracer->inSpan('latency_probe.publication.'.$phase, [
            'run_id' => $runId, 'component' => 'runtime_publication', 'latency_probe' => true,
        ], $operation);
    }
}
