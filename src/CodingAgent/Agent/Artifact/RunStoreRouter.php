<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * RunStoreInterface router that delegates between parent-scoped and
 * child-scoped stores transparently.
 *
 * For parent (top-level) run IDs, delegates to SessionRunStore.
 * For child agent run IDs, creates per-instance AgentChildRunStore
 * and delegates to it.
 *
 * Child run location: the router first tries the parent store; if no
 * state exists at the top level, it consults a lazy locator that scans
 * known parent sessions and their AgentArtifactRegistry entries to find
 * the parentRunId → artifactId mapping for the child run.  Once located,
 * the mapping is cached per-process so subsequent calls are fast.
 */
final class RunStoreRouter implements RunStoreInterface
{
    /** @var array<string, AgentChildRunStore> agentRunId → store */
    private array $childStores = [];

    public function __construct(
        private readonly RunStoreInterface $parentStore,
        private readonly AgentChildRunStoreFactory $childStoreFactory,
        private readonly AgentChildRunLocator $childRunLocator,
    ) {
    }

    public function get(string $runId): ?RunState
    {
        $state = $this->parentStore->get($runId);
        if (null !== $state) {
            return $state;
        }

        $childStore = $this->resolveChildStore($runId);
        if (null === $childStore) {
            return null;
        }

        return $childStore->get($runId);
    }

    public function compareAndSwap(RunState $state, int $expectedVersion): bool
    {
        $runId = $state->runId;

        // Parent store handles parent runs and will reject child runIds.
        $parentHandles = $this->parentStore->get($runId);
        if (null !== $parentHandles) {
            return $this->parentStore->compareAndSwap($state, $expectedVersion);
        }

        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->compareAndSwap($state, $expectedVersion);
        }

        // Not found in parent OR child — fall back to parent for
        // consistent error behavior.
        return $this->parentStore->compareAndSwap($state, $expectedVersion);
    }

    public function findRunningStaleBefore(\DateTimeImmutable $updatedBefore): array
    {
        $stale = $this->parentStore->findRunningStaleBefore($updatedBefore);

        // FIXME: scanning all child runs for stale detection requires
        // iterating all parent sessions and their artifacts — defer to
        // a future task (the stale-run detection flow is parent-centric
        // in v1; child run liveness is managed by the subagent tool's
        // own timeout).
        return $stale;
    }

    /**
     * Resolve a child store for the given agentRunId, or null when the
     * run is not a known child run.
     */
    private function resolveChildStore(string $runId): ?AgentChildRunStore
    {
        if (isset($this->childStores[$runId])) {
            return $this->childStores[$runId];
        }

        $entry = $this->childRunLocator->locate($runId);
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
