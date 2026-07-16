<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch;

/**
 * Preparation failed for one batch index before any later runtime start in this attempt.
 */
final class DeferredAgentChildBatchPreparationFailure extends \RuntimeException
{
    public function __construct(
        public readonly int $failureBatchIndex,
        \Throwable $previous,
    ) {
        parent::__construct('Deferred agent child batch child preparation failed.', 0, $previous);
    }
}
