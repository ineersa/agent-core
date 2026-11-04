<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\CodingAgent\Agent\Tool\AgentResumeToolDefinitionBuilder;
use Ineersa\CodingAgent\Agent\Tool\AgentResumeToolHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentResumeToolDefinitionBuilder::class)]
final class AgentResumeToolDefinitionBuilderTest extends TestCase
{
    public function testDefinitionMentionsForkResumeWithoutWorkflowRequirements(): void
    {
        $definition = AgentResumeToolDefinitionBuilder::build(
            new \stdClass(),
        );

        $this->assertSame(AgentResumeToolHandler::NAME, $definition->name);
        $this->assertStringContainsString('subagent or fork', $definition->description);
        $this->assertStringContainsString('you will be notified when it finishes. Do not poll for completion.', $definition->description);
        $this->assertStringContainsString('subagent or fork', $definition->promptLine);
        $guidelines = implode("\n", $definition->promptGuidelines);
        $this->assertStringContainsString('terminal child or fork in the current parent lifetime', $guidelines);
        $this->assertStringContainsString('remains resumable', $guidelines);
        $this->assertStringContainsString('explicitly refuses', $guidelines);
        $this->assertStringContainsString('previous parent lifetime or exceeds its context budget', $guidelines);
        $this->assertStringContainsString('replacement via subagent or fork with the relevant handoff', $guidelines);
        $this->assertStringContainsString('wait for completion or use agent_steer', $guidelines);
        $this->assertStringNotContainsString('checkout ownership', $guidelines);
        $this->assertStringNotContainsString('update_task', $guidelines);
    }
}
