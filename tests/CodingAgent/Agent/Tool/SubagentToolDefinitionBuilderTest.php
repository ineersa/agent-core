<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolDefinitionBuilder;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class SubagentToolDefinitionBuilderTest extends IsolatedKernelTestCase
{
    public function testBuildDoesNotSetToolExecutorTimeout(): void
    {
        $handler = self::getContainer()->get(SubagentToolHandler::class);
        $def = SubagentToolDefinitionBuilder::build($handler);

        $this->assertNull($def->timeoutSeconds);
        $this->assertSame($handler, $def->handler);
        $this->assertStringContainsString('full handoff inline', $def->description);
    }

    public function testGuidanceUsesIndependentSingleChildCallsAndSerializesDependencies(): void
    {
        $handler = self::getContainer()->get(SubagentToolHandler::class);
        $def = SubagentToolDefinitionBuilder::build($handler);

        $this->assertNull($def->parametersJsonSchema);
        $this->assertSame(ToolExecutionMode::Parallel, $def->executionMode);
        $this->assertStringContainsString('one interactive foreground subagent with "agent" and "task"', $def->description);
        $this->assertStringNotContainsString('"tasks"', $def->description);
        $this->assertStringContainsString('full handoff inline', $def->description);
        $this->assertStringContainsString('agent_retrieve', $def->description);

        $this->assertSame([
            'Use {"agent":"...","task":"..."} for one child. Submit separate independent tool calls together to run them concurrently, like Bash or fork calls. Wait for prerequisites before submitting dependent work.',
            'Concurrent writers must not target the same worktree or file. Preserve worktree ownership when delegating.',
            'Success includes the full handoff inline. Responses over 50,000 characters return a notice and artifact references instead. Use agent_retrieve with the Artifact: ID for omitted handoffs, failures, metadata, or history.',
        ], $def->promptGuidelines);
    }
}
