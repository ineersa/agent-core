<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

final readonly class ChildRunBatchDTO
{
    /**
     * @param list<PreparedAgentChildRunDTO> $children
     */
    public function __construct(
        public string $parentRunId,
        public array $children,
        public int $timeoutSeconds,
        public ChildRunBatchExecutionModeEnum $executionMode,
        public ChildRunBatchLifecyclePolicyDTO $lifecyclePolicy,
    ) {
    }

    public function isSingle(): bool
    {
        return ChildRunBatchExecutionModeEnum::Single === $this->executionMode;
    }
}
