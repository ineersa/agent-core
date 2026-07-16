<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;

final class ForkExecutionService implements ForkExecutionServiceInterface
{
    public function __construct(
        private readonly DeferredSubagentBatchLaunchService $deferredBatchLaunch,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly ForkDeferredChildPreparationStrategy $forkPreparation,
    ) {
    }

    public function execute(
        string $parentRunId,
        string $task,
        ?string $modelOverride = null,
        ?string $reasoningOverride = null,
    ): DeferredToolCompletionOutcome {
        if ($this->metadataReader->isAgentChild($parentRunId)) {
            throw new ToolCallException('Nested fork launches are not supported.', retryable: false);
        }

        $this->forkPreparation->configureLaunch($task, $modelOverride, $reasoningOverride);

        return $this->deferredBatchLaunch->launch(
            $parentRunId,
            [new SubagentTaskDTO(agent: 'fork', task: $task)],
            ChildRunBatchExecutionModeEnum::Single,
            $this->forkPreparation,
        );
    }
}
