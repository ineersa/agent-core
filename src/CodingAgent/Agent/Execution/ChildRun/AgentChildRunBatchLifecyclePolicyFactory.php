<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLifecyclePolicyDTO;

final class AgentChildRunBatchLifecyclePolicyFactory
{
    public function forKind(AgentArtifactKindEnum $kind): ChildRunBatchLifecyclePolicyDTO
    {
        return match ($kind) {
            AgentArtifactKindEnum::Fork => new ChildRunBatchLifecyclePolicyDTO(
                parentCancelSingleReason: 'Parent run cancelled fork tool.',
                parentCancelParallelReason: 'Parent run cancelled fork tool.',
                singleTimeoutCancelReason: 'Fork timed out.',
                parallelTimeoutCancelReason: 'Fork timed out.',
                launchAbortSiblingCancelReason: 'Fork launch aborted after sibling failure.',
            ),
            AgentArtifactKindEnum::Subagent => new ChildRunBatchLifecyclePolicyDTO(
                parentCancelSingleReason: 'Parent run cancelled subagent tool.',
                parentCancelParallelReason: 'Parent run cancelled parallel subagent tool.',
                singleTimeoutCancelReason: 'Subagent timed out.',
                parallelTimeoutCancelReason: 'Parallel subagent timed out.',
                launchAbortSiblingCancelReason: 'Parallel subagent launch aborted after sibling failure.',
            ),
        };
    }
}
