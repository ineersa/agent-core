<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

/**
 * Runtime child start failed after canonical launch-abort cleanup (Piece 4A).
 */
final class DeferredSubagentBatchRuntimeStartFailure extends \RuntimeException
{
    public function __construct(
        public readonly int $failureBatchIndex,
        \Throwable $previous,
    ) {
        parent::__construct('Deferred subagent batch runtime start failed.', 0, $previous);
    }
}
