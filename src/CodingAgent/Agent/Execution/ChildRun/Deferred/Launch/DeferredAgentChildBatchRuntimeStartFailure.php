<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch;

/**
 * Runtime child start failed after canonical launch-abort cleanup.
 */
final class DeferredAgentChildBatchRuntimeStartFailure extends \RuntimeException
{
    public function __construct(
        public readonly int $failureBatchIndex,
        \Throwable $previous,
    ) {
        parent::__construct('Deferred agent child batch runtime start failed.', 0, $previous);
    }
}
