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

        self::assertStringContainsString('<agents_instructions>', $output);
        self::assertStringContainsString('<available_agents>', $output);
        self::assertStringContainsString('<name>scout</name>', $output);
        self::assertStringContainsString('<description>Explore the codebase</description>', $output);
        self::assertStringNotContainsString('# Scout body', $output);
    }

    public function testRenderAvailableAgentsEmptyReturnsEmptyString(): void
    {
        self::assertSame('', $this->renderer->renderAvailableAgents([]));
    }

    public function testRenderAvailableAgentsSortsByName(): void
    {
        $agents = [
            new AgentDefinitionDTO(name: 'zeta', description: 'Z', tools: ['read'], mcp: new McpPolicyDTO(McpAgentModeEnum::None, [])),
            new AgentDefinitionDTO(name: 'alpha', description: 'A', tools: ['read'], mcp: new McpPolicyDTO(McpAgentModeEnum::None, [])),
        ];

        $output = $this->renderer->renderAvailableAgents($agents);
        self::assertLessThan(strpos($output, '<name>zeta</name>'), strpos($output, '<name>alpha</name>'));
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

        self::assertStringContainsString('review&amp;er', $output);
        self::assertStringNotContainsString('SECRET_INSTRUCTIONS', $output);
    }
}
