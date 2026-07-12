<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\AgentChildHandoffRenderer;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchCompletionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchSupervisionResultDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;

final class SubagentSupervisionResultMapper
{
    public function __construct(
        private readonly SubagentParallelAggregateResultFormatter $parallelFormatter,
        private readonly AgentChildHandoffRenderer $handoffRenderer,
        private readonly AgentsConfig $agentsConfig,
    ) {
    }

    public function mapSingle(ChildRunBatchSupervisionResultDTO $result): string
    {
        return match ($result->completionKind) {
            ChildRunBatchCompletionKindEnum::SingleSucceeded, ChildRunBatchCompletionKindEnum::SingleTimedOut => $result->singleChildToolResult
                ?? throw new ToolCallException('Single child supervision missing tool result.', retryable: false),
            ChildRunBatchCompletionKindEnum::ParentCancelled => $this->throwParentCancelledSingle($result),
            default => throw new ToolCallException('Unexpected single child supervision outcome: '.$result->completionKind->value, retryable: false),
        };
    }

    public function mapParallel(ChildRunBatchSupervisionResultDTO $result): string
    {
        return match ($result->completionKind) {
            ChildRunBatchCompletionKindEnum::AllSucceeded => $this->parallelFormatter->formatSuccess($result),
            ChildRunBatchCompletionKindEnum::LaunchAborted => throw new ToolCallException('Parallel subagent launch failed: '.($result->launchFailure?->getMessage() ?? 'unknown error')."\n\n".$this->parallelFormatter->formatReport($result), retryable: false, previous: $result->launchFailure),
            ChildRunBatchCompletionKindEnum::ParentCancelled => throw new ToolCallException("Parallel subagent tool cancelled by parent run.\n\n".$this->parallelFormatter->formatReport($result), retryable: false),
            ChildRunBatchCompletionKindEnum::BatchTimedOut => throw new ToolCallException(\sprintf('Parallel subagents timed out after %d seconds.', $this->agentsConfig->subagentToolTimeoutSeconds)."\n\n".$this->parallelFormatter->formatReport($result), retryable: false),
            ChildRunBatchCompletionKindEnum::PartialFailure => throw new ToolCallException('Parallel subagent execution failed for one or more children.'."\n\n".$this->parallelFormatter->formatReport($result), retryable: false),
            default => throw new ToolCallException('Unexpected parallel supervision outcome: '.$result->completionKind->value, retryable: false),
        };
    }

    private function throwParentCancelledSingle(ChildRunBatchSupervisionResultDTO $result): never
    {
        $item = $result->items[0] ?? null;
        if (null === $item) {
            throw new ToolCallException('Subagent cancelled by parent run.', retryable: false);
        }
        throw new ToolCallException($this->handoffRenderer->formatParentCancelledSingleMessage($item->identity->displayName, $item->identity->artifactId), retryable: false);
    }
}
