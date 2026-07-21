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
        $def = SubagentToolDefinitionBuilder::build(new AgentsConfig(subagentToolTimeoutSeconds: 1800), $handler);

        $this->assertNull($def->timeoutSeconds);
        $this->assertStringContainsString('full child handoff inline', $def->description);
    }

    /**
     * Thesis: provider tool schema stays on the proven main shape, while
     * promptGuidelines carry the independent-batch / dependent-serialize
     * decision rule and Artifact/agent_retrieve retrieval guidance.
     */
    public function testBuildGuidanceRequiresIndependentBatchAndDependentSerialization(): void
    {
        $handler = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler::class);
        $def = SubagentToolDefinitionBuilder::build(new AgentsConfig(subagentToolTimeoutSeconds: 1800), $handler);

        // Provider schema must remain cache-stable with origin/main wording.
        $this->assertStringContainsString('Single mode uses "agent" and "task"', $def->description);
        $this->assertStringContainsString('Parallel mode uses "tasks"', $def->description);
        $this->assertStringContainsString('full child handoff inline', $def->description);
        $this->assertStringContainsString('agent_retrieve', $def->description);

        $tasksDescription = $def->parametersJsonSchema['properties']['tasks']['description'] ?? '';
        $this->assertIsString($tasksDescription);
        $this->assertStringContainsString('Use instead of agent/task for parallel mode', $tasksDescription);

        $guidelines = implode("\n", $def->promptGuidelines);
        $this->assertLessThanOrEqual(5, \count($def->promptGuidelines));
        $this->assertStringContainsString('{"tasks":[{"agent":"...","task":"..."}]}', $guidelines);
        $this->assertStringContainsString('{"agent":"...","task":"..."}', $guidelines);
        $this->assertStringContainsString('dependent', $guidelines);
        $this->assertStringContainsString('serialize', $guidelines);
        $this->assertStringContainsString('cap overflow', $guidelines);
        $this->assertStringContainsString('Artifact:', $guidelines);
        $this->assertStringContainsString('agent_retrieve', $guidelines);
    }
}
