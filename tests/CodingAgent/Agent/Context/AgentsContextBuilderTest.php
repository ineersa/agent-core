<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Context;

use Ineersa\CodingAgent\Agent\Context\AgentContextRenderer;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder
 */
final class AgentsContextBuilderTest extends TestCase
{
    public function testBuildReturnsAvailableAgentsForEnabledForegroundAgents(): void
    {
        $scout = new AgentDefinitionDTO(
            name: 'scout',
            description: 'Scout agent',
            tools: ['read'],
            mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
        );
        $disabled = new AgentDefinitionDTO(
            name: 'worker',
            description: 'Disabled worker',
            tools: ['read'],
            mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
            disabled: true,
        );
        $bgOnly = new AgentDefinitionDTO(
            name: 'bg-only',
            description: 'Background only',
            tools: ['read'],
            mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
            foregroundAllowed: false,
        );

        $builder = new AgentsContextBuilder(
            new AgentDefinitionCatalog([$scout, $disabled, $bgOnly]),
            new AgentsConfig(enabled: true),
            new AgentContextRenderer(),
        );

        $output = $builder->build();

        self::assertStringContainsString('<available_agents>', $output);
        self::assertStringContainsString('<name>scout</name>', $output);
        self::assertStringNotContainsString('<name>worker</name>', $output);
        self::assertStringNotContainsString('<name>bg-only</name>', $output);
        self::assertStringNotContainsString('Disabled worker', $output);
    }

    public function testBuildReturnsEmptyWhenAgentsDisabledInConfig(): void
    {
        $scout = new AgentDefinitionDTO(
            name: 'scout',
            description: 'Scout agent',
            tools: ['read'],
            mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
        );

        $builder = new AgentsContextBuilder(
            new AgentDefinitionCatalog([$scout]),
            new AgentsConfig(enabled: false),
            new AgentContextRenderer(),
        );

        self::assertSame('', $builder->build());
    }

    public function testBuildIncludesRepresentativeParsedAgentNames(): void
    {
        $definitions = [
            new AgentDefinitionDTO(
                name: 'scout',
                description: 'Fast codebase recon that returns compressed context for handoff',
                tools: ['read'],
                mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
            ),
            new AgentDefinitionDTO(
                name: 'reviewer',
                description: 'Senior code reviewer',
                tools: ['read', 'bash'],
                mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
            ),
        ];

        $builder = new AgentsContextBuilder(
            new AgentDefinitionCatalog($definitions),
            new AgentsConfig(enabled: true),
            new AgentContextRenderer(),
        );

        $output = $builder->build();

        self::assertStringContainsString('<name>reviewer</name>', $output);
        self::assertStringContainsString('<name>scout</name>', $output);
        self::assertStringNotContainsString('You are a scout', $output);
    }

    public function testBuildReturnsEmptyWhenNoLaunchableAgents(): void
    {
        $builder = new AgentsContextBuilder(
            new AgentDefinitionCatalog([]),
            new AgentsConfig(enabled: true),
            new AgentContextRenderer(),
        );

        self::assertSame('', $builder->build());
    }
}
