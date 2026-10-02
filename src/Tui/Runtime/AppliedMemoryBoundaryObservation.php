<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * Coalesced scalar memory-boundary marks from one poll batch.
 *
 * Marks come from successful apply transitions only: activity entering a
 * terminal enum value, or isCompacting flipping true→false. Endpoint
 * activity after the whole batch is not enough, because a batch can
 * terminalize then queue a follow-up, or start and settle compaction in
 * the same poll.
 */
final readonly class AppliedMemoryBoundaryObservation
{
    public function __construct(
        public bool $activityBecameTerminal = false,
        public bool $compactionSettled = false,
        public ?string $activityBefore = null,
        public ?string $terminalActivity = null,
        public int $lastSeqBefore = 0,
        public int $lastSeqAfter = 0,
    ) {
    }
}
