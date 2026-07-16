<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch;

/**
 * Typed deferred child batch launch failure for kind-specific adapters to map to ToolCallException.
 */
final class DeferredAgentChildBatchLaunchException extends \RuntimeException
{
    public function __construct(
        public readonly DeferredAgentChildBatchLaunchFailureReasonEnum $reason,
        ?\Throwable $previous = null,
        public readonly ?int $failureBatchIndex = null,
    ) {
        parent::__construct('Deferred agent child batch launch failed: '.$reason->value, 0, $previous);
    }
}
