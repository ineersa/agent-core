<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Context;

use Ineersa\CodingAgent\Agent\Context\AgentContextRenderer;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Agent\Context\AgentContextRenderer
 */
final class AgentContextRendererTest extends TestCase
{
    private AgentContextRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new AgentContextRenderer();
    }

    public function testRenderAvailableAgentsIncludesNameAndDescription(): void
    {
        $agents = [
            new AgentDefinitionDTO(
                name: 'scout',
                description: 'Explore the codebase',
                tools: ['read'],
                mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
            ),
        ];

        $output = $this->renderer->renderAvailableAgents($agents);

        $this->assertStringContainsString('<agents_instructions>', $output);
        $this->assertStringContainsString('<available_agents>', $output);
        $this->assertStringContainsString('<name>scout</name>', $output);
        $this->assertStringContainsString('<description>Explore the codebase</description>', $output);
        $this->assertStringNotContainsString('# Scout body', $output);
    }

    public function testRenderAvailableAgentsEmptyReturnsEmptyString(): void
    {
        $this->assertSame('', $this->renderer->renderAvailableAgents([]));
    }

    public function testRenderAvailableAgentsSortsByName(): void
    {
        $agents = [
            new AgentDefinitionDTO(name: 'zeta', description: 'Z', tools: ['read'], mcp: new McpPolicyDTO(McpAgentModeEnum::None, [])),
            new AgentDefinitionDTO(name: 'alpha', description: 'A', tools: ['read'], mcp: new McpPolicyDTO(McpAgentModeEnum::None, [])),
        ];

        $output = $this->renderer->renderAvailableAgents($agents);
        $this->assertLessThan(strpos($output, '<name>zeta</name>'), strpos($output, '<name>alpha</name>'));
    }

    public function testXmlEscapingInDescription(): void
    {
        $agents = [
            new AgentDefinitionDTO(
                name: 'review&er',
                description: 'Runs "review" for <tasks>',
                tools: ['read'],
                mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
                instructions: 'SECRET_INSTRUCTIONS',
            ),
        ];

        $output = $this->renderer->renderAvailableAgents($agents);

        $this->assertStringContainsString('review&amp;er', $output);
        $this->assertStringNotContainsString('SECRET_INSTRUCTIONS', $output);
    }
}
