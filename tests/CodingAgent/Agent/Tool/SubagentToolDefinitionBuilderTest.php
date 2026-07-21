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
        $this->assertStringContainsString('full handoff inline', $def->description);
    }

    /**
     * Thesis: compact model-visible subagent metadata must still encode
     * independent-work batching, dependent serialization, and Artifact/
     * agent_retrieve retrieval — without requiring verbose example labels.
     */
    public function testBuildGuidanceRequiresIndependentBatchAndDependentSerialization(): void
    {
        $handler = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler::class);
        $def = SubagentToolDefinitionBuilder::build(new AgentsConfig(subagentToolTimeoutSeconds: 1800), $handler);

        $description = $def->description;
        $this->assertStringContainsString('Batch independent', $description);
        $this->assertStringContainsString('{"tasks":[{"agent":"...","task":"..."}]}', $description);
        $this->assertStringContainsString('{"agent":"...","task":"..."}', $description);
        $this->assertStringContainsString('dependent', $description);
        $this->assertStringContainsString('serialize', $description);
        $this->assertStringContainsString('agent_retrieve', $description);
        $this->assertStringContainsString('Artifact:', $description);

        $guidelines = implode("\n", $def->promptGuidelines);
        $this->assertLessThanOrEqual(5, \count($def->promptGuidelines));
        $this->assertStringContainsString('{"tasks":[{"agent":"...","task":"..."}]}', $guidelines);
        $this->assertStringContainsString('{"agent":"...","task":"..."}', $guidelines);
        $this->assertStringContainsString('dependent', $guidelines);
        $this->assertStringContainsString('concurrent', $guidelines);
        $this->assertStringContainsString('serialize', $guidelines);
        $this->assertStringContainsString('cap overflow', $guidelines);
        $this->assertStringContainsString('Artifact:', $guidelines);
        $this->assertStringContainsString('agent_retrieve', $guidelines);

        $this->assertStringContainsString('batch independent', strtolower($def->promptLine));

        $tasksDescription = $def->parametersJsonSchema['properties']['tasks']['description'] ?? '';
        $this->assertIsString($tasksDescription);
        $this->assertStringContainsString('independent', $tasksDescription);
    }
}
