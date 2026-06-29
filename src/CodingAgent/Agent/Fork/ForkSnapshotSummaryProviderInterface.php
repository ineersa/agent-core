<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Pluggable summary provider for fork snapshot virtual compaction.
 *
 * In v1 the default implementation is a NOOP (returns the carried-forward
 * prior summary only).  An LLM-backed provider is a later task.
 *
 * The compactor calls this when present and non-null to produce a
 * synthetic summary message (role=user, metadata compact_summary=true).
 */
interface ForkSnapshotSummaryProviderInterface
{
    /**
     * Produce a summary for the discarded portion of a fork snapshot.
     *
     * @param list<AgentMessage> $discarded             The messages being summarized away
     * @param string|null        $carriedForwardSummary Prior compact_summary text, if any
     *
     * @return string|null Summary text, or null if no summary can be produced
     */
    public function summarizeForSnapshot(array $discarded, ?string $carriedForwardSummary): ?string;
}
