<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;

final class ForkToolPolicyResolver
{
    public function __construct(
        private readonly AgentToolPolicyResolver $toolPolicyResolver,
    ) {
    }

    /**
     * @return array{tools: list<string>, mcp: array{mode: string, tools: list<string>}}
     */
    public function resolve(string $parentRunId): array
    {
        $definition = new AgentDefinitionDTO(
            name: 'fork',
            description: 'fork child',
            tools: null,
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::All),
            instructions: '',
            inheritProjectContext: true,
            inheritAgentsMd: true,
        );

        $policy = $this->toolPolicyResolver->resolve($definition, $parentRunId, allowSubagent: false);
        $policy['tools'] = array_values(array_filter(
            $policy['tools'],
            static fn (string $name): bool => 'fork' !== $name && 'subagent' !== $name,
        ));

        return $policy;
    }
}
