<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\CodingAgent\Agent\Tool\SubagentToolDefinitionBuilder;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class SubagentToolDefinitionBuilderTest extends IsolatedKernelTestCase
{
    public function testBuildDoesNotSetToolExecutorTimeout(): void
    {
        $handler = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler::class);
        $def = SubagentToolDefinitionBuilder::build(new AgentsConfig(subagentToolTimeoutSeconds: 86400), $handler);

        $this->assertNull($def->timeoutSeconds);
        $this->assertStringContainsString('full child handoff inline', $def->description);
    }

    /**
     * Decision 102: identifier minLength/nested descriptions plus the
     * independent-batch / concurrent-max / agent_retrieve guideline set.
     */
    public function testBuildGuidanceRequiresIndependentBatchAndDependentSerialization(): void
    {
        $handler = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler::class);
        $def = SubagentToolDefinitionBuilder::build(new AgentsConfig(subagentToolTimeoutSeconds: 86400), $handler);

        $this->assertStringContainsString('Single mode uses "agent" and "task"', $def->description);
        $this->assertStringContainsString('Parallel mode uses "tasks"', $def->description);
        $this->assertStringContainsString('full child handoff inline', $def->description);
        $this->assertStringContainsString('agent_retrieve', $def->description);

        $properties = $def->parametersJsonSchema['properties'];
        $this->assertSame(1, $properties['agent']['minLength'] ?? null);
        $this->assertSame(1, $properties['task']['minLength'] ?? null);

        $taskItems = $properties['tasks']['items']['properties'] ?? [];
        $this->assertSame(1, $taskItems['agent']['minLength'] ?? null);
        $this->assertSame(1, $taskItems['task']['minLength'] ?? null);
        $this->assertSame('Agent definition name.', $taskItems['agent']['description'] ?? null);
        $this->assertSame('Task text.', $taskItems['task']['description'] ?? null);

        $tasksDescription = $properties['tasks']['description'] ?? '';
        $this->assertIsString($tasksDescription);
        $this->assertStringContainsString('Use instead of agent/task for parallel mode', $tasksDescription);

        $this->assertSame([
            'Batch independent scouts/reviewers in one {"tasks":[{"agent":"...","task":"..."}]} call; use {"agent":"...","task":"..."} for one child or dependent/serialized work.',
            'Tasks in one call run concurrently (max 4).',
            'Single-mode success includes full handoff inline (agent_retrieve optional). Parallel results are bounded summaries — use agent_retrieve with each Artifact: ID for complete handoffs, failures, metadata, or history.',
        ], $def->promptGuidelines);
    }
}
