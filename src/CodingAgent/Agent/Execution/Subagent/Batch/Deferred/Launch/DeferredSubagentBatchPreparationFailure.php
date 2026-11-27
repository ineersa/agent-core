<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

/**
 * Preparation of the deferred child failed before runtime start.
 */
final class DeferredSubagentBatchPreparationFailure extends \RuntimeException
{
    public function __construct(
        \Throwable $previous,
    ) {
        parent::__construct('Deferred subagent batch child preparation failed.', 0, $previous);
    }
}
