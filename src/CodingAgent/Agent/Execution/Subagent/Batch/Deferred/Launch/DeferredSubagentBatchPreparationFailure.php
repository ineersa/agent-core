<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

/**
 * Preparation failed for one batch index before any later runtime start in this attempt.
 */
final class DeferredSubagentBatchPreparationFailure extends \RuntimeException
{
    public function __construct(
        public readonly int $failureBatchIndex,
        \Throwable $previous,
    ) {
        parent::__construct('Deferred subagent batch child preparation failed.', 0, $previous);
    }
}
