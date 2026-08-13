<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\SubagentProgress;

/**
 * Typed parent {@code subagent_progress} snapshot (single or parallel).
 *
 * Normalize only at RunEvent / RuntimeEvent / transcript metadata boundaries.
 * Internal code should pass the concrete DTO implementations.
 */
interface SubagentProgressSnapshotInterface
{
    public function status(): string;

    public function isParallel(): bool;
}
