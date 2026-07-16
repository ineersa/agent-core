<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentLaunchDefinitionPolicyService;

final class ForkLaunchPreparationService
{
    public function __construct(
        private readonly SubagentLaunchDefinitionPolicyService $definitionPolicy,
        private readonly ChildRunArtifactLifecycleService $artifactLifecycle,
        private readonly ForkToolPolicyResolver $forkToolPolicyResolver,
    ) {
    }

    public function assertDepthAllowed(string $parentRunId): void
    {
        $this->definitionPolicy->assertDepthAllowed($parentRunId);
    }

    public function reserveIdentity(ChildRunIdentityDTO $identity): void
    {
        $this->artifactLifecycle->reservePending($identity);
    }

    /**
     * @return array{tools: list<string>, mcp: array<string, mixed>}
     */
    public function resolveToolPolicy(string $parentRunId): array
    {
        return $this->forkToolPolicyResolver->resolve($parentRunId);
    }
}
