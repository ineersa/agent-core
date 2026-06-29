<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

/**
 * Default NOOP summary provider for fork snapshot compaction.
 *
 * Returns the carried-forward prior summary only.  Does NOT call an LLM
 * or produce new summary content — this is a placeholder until an
 * LLM-backed provider is implemented in a later task.
 */
final readonly class DefaultForkSnapshotSummaryProvider implements ForkSnapshotSummaryProviderInterface
{
    public function summarizeForSnapshot(array $discarded, ?string $carriedForwardSummary): ?string
    {
        return $carriedForwardSummary;
    }
}
