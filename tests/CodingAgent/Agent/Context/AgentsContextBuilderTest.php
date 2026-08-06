<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Context;

use Ineersa\CodingAgent\Agent\Context\AgentContextRenderer;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder
 */
final class AgentsContextBuilderTest extends TestCase
{
    public function testBuildReturnsAvailableAgentsForDiscoveredAgents(): void
    {
        $scout = new AgentDefinitionDTO(
            name: 'scout',
            description: 'Scout agent',
            tools: ['read'],
        );
        $worker = new AgentDefinitionDTO(
            name: 'worker',
            description: 'Worker agent',
            tools: ['read'],
        );

        $builder = new AgentsContextBuilder(
            new AgentDefinitionCatalog([$scout, $worker]),
            new AgentsConfig(enabled: true),
            new AgentContextRenderer(),
        );

        $output = $builder->build();

        $this->assertStringContainsString('<available_agents>', $output);
        $this->assertStringContainsString('<name>scout</name>', $output);
        $this->assertStringContainsString('<name>worker</name>', $output);
        $this->assertStringContainsString('Worker agent', $output);
    }

    public function testBuildReturnsEmptyWhenAgentsDisabledInConfig(): void
    {
        $scout = new AgentDefinitionDTO(
            name: 'scout',
            description: 'Scout agent',
            tools: ['read'],
        );

        $builder = new AgentsContextBuilder(
            new AgentDefinitionCatalog([$scout]),
            new AgentsConfig(enabled: false),
            new AgentContextRenderer(),
        );

        $this->assertSame('', $builder->build());
    }

    public function testBuildIncludesRepresentativeParsedAgentNames(): void
    {
        $definitions = [
            new AgentDefinitionDTO(
                name: 'scout',
                description: 'Fast codebase recon that returns compressed context for handoff',
                tools: ['read'],
            ),
            new AgentDefinitionDTO(
                name: 'reviewer',
                description: 'Senior code reviewer',
                tools: ['read', 'bash'],
            ),
        ];

        $builder = new AgentsContextBuilder(
            new AgentDefinitionCatalog($definitions),
            new AgentsConfig(enabled: true),
            new AgentContextRenderer(),
        );

        $output = $builder->build();

        $this->assertStringContainsString('<name>reviewer</name>', $output);
        $this->assertStringContainsString('<name>scout</name>', $output);
        $this->assertStringNotContainsString('You are a scout', $output);
    }

    public function testBuildReturnsEmptyWhenNoLaunchableAgents(): void
    {
        $builder = new AgentsContextBuilder(
            new AgentDefinitionCatalog([]),
            new AgentsConfig(enabled: true),
            new AgentContextRenderer(),
        );

        $this->assertSame('', $builder->build());
    }
}
