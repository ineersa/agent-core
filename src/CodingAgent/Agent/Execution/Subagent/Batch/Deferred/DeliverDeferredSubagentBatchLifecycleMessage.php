<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

/**
 * Enqueues aggregate progress and terminal completion for one deferred subagent batch (Piece 4B).
 */
final readonly class DeliverDeferredSubagentBatchLifecycleMessage
{
    public function __construct(
        public string $batchLifecycleId,
    ) {
    }
}
