<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session;

/**
 * Read-only canonical session event access for recovery/compaction.
 *
 * Implementations may scan the full append-only event log. Callers must not
 * use this API on every turn or boundary; hot paths should consume the already
 * committed batch from AfterTurnCommit hooks instead.
 *
 * No branch projection and no mutation access are provided.
 */
interface SessionEventReaderInterface
{
    /**
     * Read canonical events for a run whose sequence is in [startSeq, endSeq].
     *
     * Unknown or empty sessions return an empty iterable. Inclusive bounds.
     *
     * @return iterable<SessionEventDTO>
     */
    public function readRange(string $runId, int $startSeq, int $endSeq): iterable;
}
