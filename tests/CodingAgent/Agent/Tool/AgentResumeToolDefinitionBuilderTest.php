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
    public function testDefinitionMentionsForkResumeAndOwnershipGuideline(): void
    {
        $definition = AgentResumeToolDefinitionBuilder::build(
            new AgentsConfig(maxAgents: 4),
            new \stdClass(),
        );

        $this->assertSame(AgentResumeToolHandler::NAME, $definition->name);
        $this->assertStringContainsString('subagent or fork', $definition->description);
        $this->assertStringContainsString('subagent or fork', $definition->promptLine);
        $this->assertContains(
            'Resuming a fork keeps the same child identity and conversation; the follow-up task must explicitly hand off checkout ownership and require inspecting current file state before resumed edits.',
            $definition->promptGuidelines,
        );
    }
}
