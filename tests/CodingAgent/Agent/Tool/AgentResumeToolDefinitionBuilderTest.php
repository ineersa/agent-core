<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\CodingAgent\Agent\Tool\AgentResumeToolDefinitionBuilder;
use Ineersa\CodingAgent\Agent\Tool\AgentResumeToolHandler;
use Ineersa\CodingAgent\Config\AgentsConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentResumeToolDefinitionBuilder::class)]
final class AgentResumeToolDefinitionBuilderTest extends TestCase
{
    public function testDefinitionMentionsForkResumeWithoutWorkflowRequirements(): void
    {
        $definition = AgentResumeToolDefinitionBuilder::build(
            new AgentsConfig(maxAgents: 4),
            new \stdClass(),
        );

        $this->assertSame(AgentResumeToolHandler::NAME, $definition->name);
        $this->assertStringContainsString('subagent or fork', $definition->description);
        $this->assertStringContainsString('subagent or fork', $definition->promptLine);
        $this->assertStringNotContainsString('checkout ownership', implode("\n", $definition->promptGuidelines));
    }
}
