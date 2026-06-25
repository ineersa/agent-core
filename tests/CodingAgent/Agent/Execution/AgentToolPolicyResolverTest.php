<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentToolPolicyResolver;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentToolPolicyResolver::class)]
final class AgentToolPolicyResolverTest extends TestCase
{
    public function testResolveExcludesSubagentByDefault(): void
    {
        $definition = $this->createDefinition(tools: ['read', 'subagent', 'write']);

        $resolver = $this->createResolver();
        $policy = $resolver->resolve($definition);

        self::assertNotContains('subagent', $policy['tools']);
        self::assertContains('read', $policy['tools']);
        self::assertContains('write', $policy['tools']);
    }

    public function testResolveAllowsSubagentWhenExplicitlyAllowed(): void
    {
        $definition = $this->createDefinition(tools: ['read', 'subagent']);

        $resolver = $this->createResolver();
        $policy = $resolver->resolve($definition, allowSubagent: true);

        self::assertContains('subagent', $policy['tools']);
    }

    public function testResolveEmptyToolsRemainsEmpty(): void
    {
        $definition = $this->createDefinition(tools: ['subagent']);

        $resolver = $this->createResolver();
        $policy = $resolver->resolve($definition);

        self::assertSame([], $policy['tools']);
    }

    public function testResolveMcpModeNone(): void
    {
        $definition = $this->createDefinition(tools: ['read'], mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None));

        $resolver = $this->createResolver();
        $policy = $resolver->resolve($definition);

        self::assertSame('none', $policy['mcp']['mode']);
        self::assertSame([], $policy['mcp']['tools']);
    }

    public function testResolveMcpModeSpecificMergesMcpToolsIntoAllowedList(): void
    {
        $definition = $this->createDefinition(
            tools: ['read'],
            mcp: new McpPolicyDTO(
                mode: McpAgentModeEnum::Specific,
                tools: ['context7__query-docs', 'websearch__search'],
            ),
        );

        $resolver = $this->createResolver();
        $policy = $resolver->resolve($definition);

        self::assertSame('specific', $policy['mcp']['mode']);
        // MCP tools appear in the resolved allowed tools list.
        self::assertContains('context7__query-docs', $policy['tools']);
        self::assertContains('websearch__search', $policy['tools']);
        self::assertContains('read', $policy['tools']);
        // Subagent is still excluded.
        self::assertNotContains('subagent', $policy['tools']);
    }

    public function testResolveMcpModeSpecificDoesNotDuplicateAlreadyPresentTools(): void
    {
        $definition = $this->createDefinition(
            tools: ['read', 'context7__query-docs'],
            mcp: new McpPolicyDTO(
                mode: McpAgentModeEnum::Specific,
                tools: ['context7__query-docs'],
            ),
        );

        $resolver = $this->createResolver();
        $policy = $resolver->resolve($definition);

        self::assertSame('specific', $policy['mcp']['mode']);
        // context7 should appear exactly once.
        $counts = array_count_values($policy['tools']);
        self::assertSame(1, $counts['context7__query-docs'] ?? 0);
    }

    public function testResolveMcpModeAll(): void
    {
        $definition = $this->createDefinition(
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::All),
        );

        $resolver = $this->createResolver();
        $policy = $resolver->resolve($definition);

        self::assertSame('all', $policy['mcp']['mode']);
    }

    public function testOmittedToolsInheritsActiveRegistryToolsExceptSubagent(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->method('activeToolNames')->willReturn(['read', 'bash', 'write', 'subagent', 'agent_retrieve']);
        $resolver = new AgentToolPolicyResolver($registry);

        $definition = $this->createDefinition(tools: null);
        $policy = $resolver->resolve($definition);

        self::assertContains('read', $policy['tools']);
        self::assertContains('bash', $policy['tools']);
        self::assertContains('write', $policy['tools']);
        self::assertContains('agent_retrieve', $policy['tools']);
        self::assertNotContains('subagent', $policy['tools']);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function createResolver(?ToolRegistryInterface $registry = null): AgentToolPolicyResolver
    {
        if (null === $registry) {
            $registry = $this->createStub(ToolRegistryInterface::class);
            $registry->method('activeToolNames')->willReturn(['read']);
        }

        return new AgentToolPolicyResolver($registry);
    }

    /**
     * @param list<string>|null $tools
     */
    private function createDefinition(?array $tools, ?McpPolicyDTO $mcp = null): AgentDefinitionDTO
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
