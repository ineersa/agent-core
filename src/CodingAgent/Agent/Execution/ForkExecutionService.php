<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Launch\DeferredForkBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Fork\ForkLaunchTaskDTO;

/**
 * Thin fork tool adapter: validates launch intent and delegates to durable deferred child batch launch.
 */
final class ForkExecutionService implements ForkExecutionServiceInterface
{
    public function __construct(
        private readonly DeferredForkBatchLaunchService $deferredForkLaunch,
        private readonly SubagentRunMetadataReader $metadataReader,
    ) {
    }

    public function execute(
        string $parentRunId,
        string $task,
        ?string $modelOverride = null,
        ?string $reasoningOverride = null,
    ): DeferredToolCompletionOutcome {
        $task = trim($task);
        if ('' === $task) {
            throw new ToolCallException('The fork tool requires a non-empty task.', retryable: false);
        }

        if ($this->metadataReader->isAgentChild($parentRunId)) {
            throw new ToolCallException('Nested fork launches are not supported. Fork children cannot launch another fork.', retryable: false);
        }

        return $this->deferredForkLaunch->launch(
            $parentRunId,
            new ForkLaunchTaskDTO(
                task: $task,
                modelOverride: $modelOverride,
                reasoningOverride: $reasoningOverride,
            ),
        );
    }
}
