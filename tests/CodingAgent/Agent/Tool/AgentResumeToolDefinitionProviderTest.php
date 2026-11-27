<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Agent\Tool\AgentResumeToolDefinitionProvider;
use Ineersa\CodingAgent\Agent\Tool\AgentResumeToolHandler;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(AgentResumeToolDefinitionProvider::class)]
final class AgentResumeToolDefinitionProviderTest extends TestCase
{
    public function testDefinitionMentionsForkResumeWithoutWorkflowRequirements(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $handler = new AgentResumeToolHandler($accessor, new ToolRuntime($accessor), new ServiceLocator([]));
        $definition = (new AgentResumeToolDefinitionProvider($handler))->definition();

        $this->assertSame(AgentResumeToolHandler::NAME, $definition->name);
        $this->assertStringContainsString('subagent or fork', $definition->description);
        $this->assertStringContainsString('you will be notified when it finishes. Do not poll for completion.', $definition->description);
        $this->assertStringContainsString('subagent or fork', $definition->promptLine);
        $guidelines = implode("\n", $definition->promptGuidelines);
        $this->assertStringNotContainsString('checkout ownership', $guidelines);
        $this->assertStringNotContainsString('update_task', $guidelines);
    }
}
