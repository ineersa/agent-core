<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

/**
 * Immutable per-batch strings supplied by the child-kind application layer (e.g. foreground agent tool).
 *
 * Generic child-run coordinators use these for AgentRunner cancellation reasons only.
 */
final readonly class ChildRunBatchLifecyclePolicyDTO
{
    public function __construct(
        public string $parentCancelSingleReason,
        public string $parentCancelParallelReason,
        public string $singleTimeoutCancelReason,
        public string $parallelTimeoutCancelReason,
        public string $launchAbortSiblingCancelReason,
    ) {
    }
}
