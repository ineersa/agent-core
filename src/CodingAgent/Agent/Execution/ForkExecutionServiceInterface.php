<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;

/**
 * Foreground fork launch contract (deferred completion, same shape as subagent tool backend).
 */
interface ForkExecutionServiceInterface
{
    public function execute(
        string $parentRunId,
        string $task,
        ?string $modelOverride = null,
        ?string $reasoningOverride = null,
    ): DeferredToolCompletionOutcome;
}
