<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;

/**
 * Captured local coordination for tool-invocation lifecycle projection updates.
 * Does not enable background acknowledgment or capability exposure by itself.
 */
final readonly class RecordToolInvocationLifecycleDTO
{
    public function __construct(
        public DeferredToolCompletionCorrelation $expectedCorrelation,
        public ToolInvocationCallerDispositionEnum $callerDisposition,
        public ToolInvocationExecutionObservationEnum $executionObservation,
    ) {
    }
}
