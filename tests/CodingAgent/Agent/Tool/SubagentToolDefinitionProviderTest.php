<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolDefinitionProvider;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class SubagentToolDefinitionProviderTest extends TestCase
{
    public function testDefinitionDescribesSingleChildCallsWithoutToolExecutorTimeout(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $handler = new SubagentToolHandler($accessor, new ToolRuntime($accessor), new ServiceLocator([]));
        $def = (new SubagentToolDefinitionProvider($handler))->definition();

        $this->assertNull($def->timeoutSeconds);
        $this->assertSame($handler, $def->handler);
        $this->assertStringContainsString('wait for completion and return its handoff', $def->description);

        $this->assertNull($def->parametersJsonSchema);
        $this->assertSame(ToolExecutionMode::Parallel, $def->executionMode);
        $this->assertStringContainsString('one subagent with agent and task', $def->description);
        $this->assertStringNotContainsString('"tasks"', $def->description);
        $this->assertStringContainsString('you will be notified when it finishes. Do not poll for completion.', $def->description);
        $this->assertStringContainsString('agent_retrieve', $def->description);

        $this->assertSame([
            'Use {"agent":"...","task":"..."} for one child. Submit separate independent tool calls together to run them concurrently. Wait for prerequisites before submitting dependent work.',
            'Concurrent writers must not target the same worktree or file. Preserve worktree ownership when delegating.',
            'Foreground calls return the handoff inline; background calls deliver it automatically. Responses over 50,000 characters return artifact references instead. Use agent_retrieve with the Artifact: ID for omitted handoffs, failures, metadata, or history.',
        ], $def->promptGuidelines);
    }
}
