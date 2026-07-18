<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;

final class ForkInternalAgentDefinition
{
    public static function create(?string $model = null): AgentDefinitionDTO
    {
        return new AgentDefinitionDTO(
            name: 'fork',
            description: 'Internal fork child (tool specialization, not catalog-selectable)',
            tools: null,
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::All),
            model: $model,
            instructions: '',
            inheritProjectContext: true,
            inheritAgentsMd: true,
            foregroundAllowed: true,
            parallelAllowed: false,
            disabled: true,
        );
    }
}
