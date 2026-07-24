<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Preparation;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class SubagentChildLaunchModelInheritanceTest extends IsolatedKernelTestCase
{
    public function testExplicitChildModelWinsOverParentSnapshot(): void
    {
        $factory = self::getContainer()->get(SubagentChildLaunchInputFactory::class);
        \assert($factory instanceof SubagentChildLaunchInputFactory);

        $prepared = $factory->buildPrepared(
            identity: $this->identity('deepseek/deepseek-v4-flash'),
            definition: $this->definition('deepseek/deepseek-v4-flash'),
            allowedTools: [],
            mcp: [],
            parentModel: 'openai-codex/gpt-5.6-sol',
        );

        $this->assertSame('deepseek/deepseek-v4-flash', $prepared->startRunInput->metadata?->model);
    }

    public function testMissingExplicitUsesParentSnapshot(): void
    {
        $factory = self::getContainer()->get(SubagentChildLaunchInputFactory::class);
        \assert($factory instanceof SubagentChildLaunchInputFactory);

        $prepared = $factory->buildPrepared(
            identity: $this->identity(null),
            definition: $this->definition(null),
            allowedTools: [],
            mcp: [],
            parentModel: 'deepseek/deepseek-v4-flash',
        );

        $this->assertSame('deepseek/deepseek-v4-flash', $prepared->startRunInput->metadata?->model);
    }

    public function testMissingParentAndExplicitFailsClosed(): void
    {
        $factory = self::getContainer()->get(SubagentChildLaunchInputFactory::class);
        \assert($factory instanceof SubagentChildLaunchInputFactory);

        $this->expectException(\RuntimeException::class);
        $factory->buildPrepared(
            identity: $this->identity(null),
            definition: $this->definition(null),
            allowedTools: [],
            mcp: [],
            parentModel: null,
        );
    }

    private function identity(?string $model): ChildRunIdentityDTO
    {
        return new ChildRunIdentityDTO(
            parentRunId: 'parent-1',
            childRunId: 'child-1',
            artifactId: 'agent_child1',
            displayName: 'scout',
            taskSummary: 'task',
            definitionModel: $model,
            artifactKind: AgentArtifactKindEnum::Subagent,
        );
    }

    private function definition(?string $model): AgentDefinitionDTO
    {
        return new AgentDefinitionDTO(
            name: 'scout',
            description: 'd',
            tools: [],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            model: $model,
            instructions: 'do work',
        );
    }
}
