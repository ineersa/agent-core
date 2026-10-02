<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;

interface ForkExecutionServiceInterface
{
    public function execute(
        string $parentRunId,
        string $task,
        ToolLaunchContextDTO $launchContext,
        ?string $modelOverride = null,
        ?string $reasoningOverride = null,
    ): DeferredToolCompletionOutcome;
}
