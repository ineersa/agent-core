<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;

final class ForkInternalAgentDefinition
{
    public static function create(?string $model = null): AgentDefinitionDTO
    {
        return new AgentDefinitionDTO(
            name: 'fork',
            description: 'Internal fork child (tool specialization, not catalog-selectable)',
            tools: null,
            model: $model,
            instructions: '',
            inheritProjectContext: true,
            parallelAllowed: false,
        );
    }
}
