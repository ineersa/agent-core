<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentToolPolicyResolver::class)]
final class AgentToolPolicyResolverTest extends TestCase
{
    public function testResolveExcludesSubagentByDefault(): void
    {
        $definition = $this->createDefinition(tools: ['read', 'subagent', 'write']);

        $resolver = new AgentToolPolicyResolver();
        $policy = $resolver->resolve($definition);

        self::assertNotContains('subagent', $policy['tools']);
        self::assertContains('read', $policy['tools']);
        self::assertContains('write', $policy['tools']);
    }

    public function testResolveAllowsSubagentWhenExplicitlyAllowed(): void
    {
        $definition = $this->createDefinition(tools: ['read', 'subagent']);

        $resolver = new AgentToolPolicyResolver();
        $policy = $resolver->resolve($definition, allowSubagent: true);

        self::assertContains('subagent', $policy['tools']);
    }

    public function testResolveEmptyToolsRemainsEmpty(): void
    {
        $definition = $this->createDefinition(tools: ['subagent']);

        $resolver = new AgentToolPolicyResolver();
        $policy = $resolver->resolve($definition);

        self::assertSame([], $policy['tools']);
    }

    public function testResolveMcpModeNone(): void
    {
        $definition = $this->createDefinition(tools: ['read'], mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None));

        $resolver = new AgentToolPolicyResolver();
        $policy = $resolver->resolve($definition);

        self::assertSame('none', $policy['mcp']['mode']);
        self::assertSame([], $policy['mcp']['tools']);
    }

    public function testResolveMcpModeSpecific(): void
    {
        $definition = $this->createDefinition(
            tools: ['read'],
            mcp: new McpPolicyDTO(
                mode: McpAgentModeEnum::Specific,
                tools: ['context7__query-docs'],
            ),
        );

        $resolver = new AgentToolPolicyResolver();
        $policy = $resolver->resolve($definition);

        self::assertSame('specific', $policy['mcp']['mode']);
        self::assertContains('context7__query-docs', $policy['mcp']['tools']);
    }

    public function testResolveMcpModeAll(): void
    {
        $definition = $this->createDefinition(
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::All),
        );

        $resolver = new AgentToolPolicyResolver();
        $policy = $resolver->resolve($definition);

        self::assertSame('all', $policy['mcp']['mode']);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * @param list<string> $tools
     */
    private function createDefinition(array $tools, ?McpPolicyDTO $mcp = null): AgentDefinitionDTO
    {
        return new AgentDefinitionDTO(
            name: 'test-agent',
            description: 'Test agent',
            tools: $tools,
            mcp: $mcp ?? new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Test instructions.',
        );
    }
}
