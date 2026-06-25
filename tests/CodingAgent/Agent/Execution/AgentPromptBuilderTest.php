<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentPromptBuilder::class)]
final class AgentPromptBuilderTest extends TestCase
{
    public function testBuildInjectsSkillsContextBeforeContractAndTask(): void
    {
        $builder = new AgentPromptBuilder();
        $def = new AgentDefinitionDTO(
            name: 'with-skill',
            description: 'd',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Do the task.',
        );

        $result = $builder->build(
            definition: $def,
            task: 'Run task',
            artifactId: 'agent_abc',
            allowedTools: ['read'],
            agentsMd: '',
            parentSystemPrompt: '',
            skillsContext: '<skill name="testing" location="/x">SKILL_BODY_UNIQUE</skill>',
        );

        $roles = array_map(static fn ($m) => $m->role, $result['messages']);
        self::assertSame(['system', 'user-context', 'user-context', 'user'], $roles);

        $skillsMsg = $result['messages'][1];
        self::assertSame('skills_context', $skillsMsg->metadata['source'] ?? null);
        self::assertStringContainsString('SKILL_BODY_UNIQUE', (string) $skillsMsg->content[0]['text']);

        $contractMsg = $result['messages'][2];
        self::assertSame('agent_child_contract', $contractMsg->metadata['source'] ?? null);
    }

    public function testBuildIncludesAgentsMdInSystemPrompt(): void
    {
        $builder = new AgentPromptBuilder();
        $def = new AgentDefinitionDTO(
            name: 'inherit',
            description: 'd',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Child instructions.',
        );

        $result = $builder->build(
            definition: $def,
            task: 't',
            artifactId: 'agent_x',
            allowedTools: ['read'],
            agentsMd: '<project_context>AGENTS_MD_UNIQUE</project_context>',
            parentSystemPrompt: '',
        );

        self::assertStringContainsString('AGENTS_MD_UNIQUE', $result['systemPrompt']);
        self::assertStringContainsString('Child instructions.', $result['systemPrompt']);
    }
}
