<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Child-aware decorator for EventStoreInterface that delegates between parent-scoped and
 * child-scoped stores transparently.
 *
 * For parent (top-level) run IDs, delegates to SessionRunEventStore.
 * For child agent run IDs, creates per-instance AgentChildRunEventStore
 * and delegates to it.
 *
 * Child run location uses AgentChildRunDirectory.
 */
final class ChildAwareEventStore implements \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface
{
    /** @var array<string, AgentChildRunEventStore> agentRunId → store */
    private array $childStores = [];

    public function __construct(
        private readonly EventStoreInterface $parentStore,
        private readonly AgentChildRunEventStoreFactory $childStoreFactory,
        private readonly AgentChildRunDirectory $childRunDirectory,
    ) {
    }

    public function appendTransition(array $events, array $work): array
    {
        $store = $this->resolveChildStore($events[0]->runId ?? $work['run_id']) ?? $this->parentStore;
        if (!$store instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }

        return $store->appendTransition($events, $work);
    }

    public function verifiedPendingTransition(string $runId): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO
    {
        $store = $this->resolveChildStore($runId) ?? $this->parentStore;
        if (!$store instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }

        return $store->verifiedPendingTransition($runId);
    }

    public function verifiedPendingBatch(string $runId, string $identity): array
    {
        $store = $this->resolveChildStore($runId) ?? $this->parentStore;
        if (!$store instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }

        return $store->verifiedPendingBatch($runId, $identity);
    }

    public function finalizeVerifiedTransition(string $runId, string $identity): void
    {
        $store = $this->resolveChildStore($runId) ?? $this->parentStore;
        if (!$store instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }
        $store->finalizeVerifiedTransition($runId, $identity);
    }

    public function assertTransitionReady(string $runId): void
    {
        $store = $this->resolveChildStore($runId) ?? $this->parentStore;
        if (!$store instanceof \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface) {
            throw new \LogicException('Configured canonical store lacks transition preparation.');
        }
        $store->assertTransitionReady($runId);
    }

    public function latestSequenceFor(string $runId): ?int
    {
        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->latestSequenceFor($runId);
        }

        return $this->parentStore->latestSequenceFor($runId);
    }

    public function firstFor(string $runId): ?RunEvent
    {
        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->firstFor($runId);
        }

        return $this->parentStore->firstFor($runId);
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->rangeFor($runId, $startSeq, $endSeq);
        }

        return $this->parentStore->rangeFor($runId, $startSeq, $endSeq);
    }

    public function reverseFor(string $runId): iterable
    {
        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->reverseFor($runId);
        }

        return $this->parentStore->reverseFor($runId);
    }

    public function allFor(string $runId): array
    {
        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->allFor($runId);
        }

        return $this->parentStore->allFor($runId);
    }

    /**
     * Resolve a child store for the given agentRunId, or null when the
     * run is not a known child run.
     */
    private function resolveChildStore(string $runId): ?AgentChildRunEventStore
    {
        if (isset($this->childStores[$runId])) {
            return $this->childStores[$runId];
        }

        $entry = $this->childRunDirectory->locate($runId);
        if (null === $entry) {
            return null;
        }

        $store = $this->childStoreFactory->create(
            parentRunId: $entry->parentRunId,
            agentRunId: $entry->agentRunId,
            artifactId: $entry->artifactId,
        );

        $this->childStores[$runId] = $store;

        return $store;
    }
}
