<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\CodingAgent\Session\History\HistoryProjectionStoreInterface;

/**
 * Resolves the latest provider-reported context token usage from the shared
 * history projection.
 *
 * Eligibility rule (projection-maintained, not archive-scanned):
 * A provider usage measurement is eligible for auto-compaction at most once.
 * An auto compaction attempt marker (trigger=auto started/failed) that is
 * newer than the measurement renders it ineligible. A newer provider
 * measurement re-opens eligibility.
 *
 * Manual /compact events are NOT considered in eligibility.
 */
final class ProviderContextUsageResolver
{
    public function __construct(
        private readonly HistoryProjectionStoreInterface $historyProjectionStore,
    ) {
    }

    /**
     * @return int|null eligible tokens, or null when no eligible measurement exists
     */
    public function getLatestEligibleInputTokens(string $runId): ?int
    {
        return $this->historyProjectionStore->get($runId)->eligibleAutoCompactionInputTokens;
    }
}
