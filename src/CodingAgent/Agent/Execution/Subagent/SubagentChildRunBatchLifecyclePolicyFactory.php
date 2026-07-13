<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO;

final class SubagentChildRunBatchLifecyclePolicyFactory
{
    public function create(): ChildRunBatchLifecyclePolicyDTO
    {
        return new ChildRunBatchLifecyclePolicyDTO(
            parentCancelSingleReason: 'Parent run cancelled subagent tool.',
            parentCancelParallelReason: 'Parent run cancelled parallel subagent tool.',
            singleTimeoutCancelReason: 'Subagent timed out.',
            parallelTimeoutCancelReason: 'Parallel subagent timed out.',
            launchAbortSiblingCancelReason: 'Parallel subagent launch aborted after sibling failure.',
        );
    }
}
