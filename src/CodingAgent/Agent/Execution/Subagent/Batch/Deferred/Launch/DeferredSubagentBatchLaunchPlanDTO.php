<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;

/** Native launch identity and input resolved once for one invocation. */
final readonly class DeferredSubagentBatchLaunchPlanDTO
{
    public function __construct(
        public string $lifecycleId,
        public DeferredSubagentBatchChildIntentDTO $childIntent,
        public ChildRunIdentityDTO $identity,
        public ?AgentDefinitionDTO $definition = null,
        public ?string $parentModel = null,
        public ?ToolLaunchContextDTO $launchContext = null,
    ) {
    }
}
