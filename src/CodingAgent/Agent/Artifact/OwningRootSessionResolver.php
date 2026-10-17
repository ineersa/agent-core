<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;

/**
 * Walks durable child ownership links to the owning root session.
 *
 * Resolution order for each hop:
 * 1. AgentChildRunDirectory (artifact registry / process cache)
 * 2. Durable deferred_subagent_child → deferred_subagent_batch.parent_run_id
 * 3. HatfieldSession.parentId for true session rows
 *
 * Child run ids are UUIDs without HatfieldSession rows. Cold workers therefore
 * cannot rely on session-list artifact scans alone for depth-2+ discovery.
 * Deferred batch reservations remain the durable parent edge.
 */
final readonly class OwningRootSessionResolver implements OwningRootSessionResolverInterface
{
    public function __construct(
        private AgentChildRunDirectory $children,
        private HatfieldSessionStore $sessions,
        private DeferredSubagentChildRepository $deferredChildren,
        private DeferredSubagentBatchRepository $deferredBatches,
    ) {
    }

    public function resolveOwningRootSessionId(string $sourceRunId): string
    {
        $current = trim($sourceRunId);
        if ('' === $current) {
            throw new \InvalidArgumentException('sourceRunId must be nonblank.');
        }
        if (false !== strpbrk($current, "/\\\0")) {
            throw new \InvalidArgumentException('sourceRunId must not contain path separators.');
        }

        $seen = [];
        while (!isset($seen[$current])) {
            $seen[$current] = true;
            $parent = $this->parentOf($current);
            if (null === $parent || $parent === $current) {
                return $current;
            }
            $current = $parent;
        }

        throw new \RuntimeException(\sprintf('Cyclic ownership while resolving root session for "%s".', $sourceRunId));
    }

    private function parentOf(string $runId): ?string
    {
        $entry = $this->children->locate($runId);
        if (null !== $entry) {
            $parent = trim($entry->parentRunId);

            return '' === $parent ? null : $parent;
        }

        $deferredParent = $this->deferredParentOf($runId);
        if (null !== $deferredParent) {
            return $deferredParent;
        }

        $session = $this->sessions->findSession($runId);
        if (null === $session) {
            return null;
        }
        $parent = $session->parentId;
        if (!\is_string($parent)) {
            return null;
        }
        $parent = trim($parent);

        return '' === $parent ? null : $parent;
    }

    private function deferredParentOf(string $childRunId): ?string
    {
        $child = $this->deferredChildren->findByChildRunId($childRunId);
        if (null === $child) {
            return null;
        }

        $batch = $this->deferredBatches->findByLifecycleId($child->batchLifecycleId);
        if (null === $batch) {
            return null;
        }

        $parent = trim($batch->parentRunId);

        return '' === $parent ? null : $parent;
    }
}
