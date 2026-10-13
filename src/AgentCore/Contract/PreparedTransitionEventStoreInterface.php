<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Event\RunEvent;

interface PreparedTransitionEventStoreInterface extends EventStoreInterface
{
    /** @param list<RunEvent> $events
     * @param array<string, mixed> $work
     *
     * @return list<RunEvent>
     */
    public function appendTransition(array $events, array $work): array;

    public function verifiedPendingTransition(string $runId): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;

    /**
     * Owner-only reconstruction of the verified staged batch.
     *
     * Public range readers must continue to honor the unpublished cut. This
     * method may read exact staged bytes only while the verified identity still
     * matches the pending transition.
     *
     * @return list<RunEvent>
     */
    public function verifiedPendingBatch(string $runId, string $identity): array;

    public function finalizeVerifiedTransition(string $runId, string $identity): void;

    public function assertTransitionReady(string $runId): void;
}
