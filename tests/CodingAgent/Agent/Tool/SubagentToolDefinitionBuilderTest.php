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
     * Thesis: model-visible subagent guidance must instruct independent-work
     * batching in one tasks call and dependent-work serialization; syntax-only
     * docs that omit the decision rule are a regression.
     */
    public function testBuildGuidanceRequiresIndependentBatchAndDependentSerialization(): void
    {
        $handler = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler::class);
        $def = SubagentToolDefinitionBuilder::build(new AgentsConfig(subagentToolTimeoutSeconds: 1800), $handler);

        $description = $def->description;
        $this->assertStringContainsString('Batch independent', $description);
        $this->assertStringContainsString('"tasks"', $description);
        $this->assertStringContainsString('serialized', $description);
        $this->assertStringContainsString('agent_retrieve', $description);

        $guidelines = implode("\n", $def->promptGuidelines);
        $this->assertStringContainsString('Decision rule', $guidelines);
        $this->assertStringContainsString('{"tasks":', $guidelines);
        // Canonical single-mode shape (not invalid pseudo-JSON like {"agent","task"}).
        $this->assertStringContainsString('{"agent":"...","task":"..."}', $guidelines);
        $this->assertStringContainsString('Anti-pattern', $guidelines);
        $this->assertStringContainsString('serialize', $guidelines);
        $this->assertStringContainsString('depends', $guidelines);
        $this->assertStringContainsString('cap overflow', $guidelines);
        $this->assertStringContainsString('Artifact:', $guidelines);
        $this->assertStringContainsString('agent_retrieve', $guidelines);

        $tasksDescription = $def->parametersJsonSchema['properties']['tasks']['description'] ?? '';
        $this->assertIsString($tasksDescription);
        $this->assertStringContainsString('independent', $tasksDescription);
    }
}
