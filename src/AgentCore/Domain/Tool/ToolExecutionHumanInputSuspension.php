<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;

/**
 * Typed toolbox outcome: tool execution is suspended pending human input.
 *
 * Unlike {@see DeferredToolCompletionOutcome}, this is not a completed tool
 * result. The execution worker must not remember it in ToolExecutionResultStore
 * and must not dispatch ToolCallResult. Exact call args remain owned by
 * ToolBatchStateDTO; this outcome only carries the pending human-input request.
 */
final readonly class ToolExecutionHumanInputSuspension
{
    public function __construct(
        public PendingHumanInputRequestDTO $request,
    ) {
    }
}
