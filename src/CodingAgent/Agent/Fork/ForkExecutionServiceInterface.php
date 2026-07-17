<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;

interface ForkExecutionServiceInterface
{
    public function execute(
        string $parentRunId,
        string $task,
        ?string $modelOverride = null,
        ?string $reasoningOverride = null,
    ): DeferredToolCompletionOutcome;
}
