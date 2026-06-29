<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Virtual compaction boundary for fork snapshots.
 *
 * Compacts ONLY the snapshot without mutating the parent session.
 * Never calls an LLM — works purely through cut-point selection and
 * carried-forward summary reuse.
 */
interface ForkSnapshotCompactorInterface
{
    /**
     * Virtually compact a sanitized message list for fork consumption.
     *
     * @param list<AgentMessage> $sanitized        Sanitized parent messages
     * @param int                $keepRecentTokens Token budget for the retained tail
     *
     * @return ForkCompactionResult Compacted result (may be no-op if under budget)
     */
    public function compact(array $sanitized, int $keepRecentTokens): ForkCompactionResult;
}
