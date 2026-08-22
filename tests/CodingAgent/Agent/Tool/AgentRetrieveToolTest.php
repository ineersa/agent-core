<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Artifact\AgentRetrieveArgumentsDTO;
use Ineersa\CodingAgent\Agent\Tool\AgentRetrieveTool;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AgentRetrieveTool::class)]
final class AgentRetrieveToolTest extends IsolatedKernelTestCase
{
    public function testDefinitionHasCorrectNameAndSchema(): void
    {
        $tool = self::getContainer()->get(AgentRetrieveTool::class);
        $def = $tool->definition();

        $this->assertSame('agent_retrieve', $def->name);
        // Typed DTO tool: schema is generated natively from
        // AgentRetrieveArgumentsDTO (parametersJsonSchema === null routes
        // through the native factory).
        $this->assertNull($def->parametersJsonSchema);
        $this->assertSame(
            'agent_retrieve artifact_id=<id>|agent_run_id=<uuid> [mode] [limit=N] — retrieve a subagent artifact',
            $def->promptLine,
        );
        $this->assertSame(
            [
                'Use agent_retrieve when parallel subagent summaries were truncated, a child failed/cancelled/timed out, or you need metadata/events/history/debug — not for successful single-mode subagent handoffs already returned inline.',
                'Provide artifact_id and/or agent_run_id from the current parent session only; cross-parent retrieval is rejected.',
                'Use metadata for status, timestamps, and counts without raw message or tool output.',
                'Use events or history for bounded debugging summaries; payloads and prompts are omitted by default.',
                'Use mode=handoff_history to list prior handoffs, or pass handoff_id=<uuid> to fetch one body. mode=handoff remains the latest handoff only.',
                'Use debug for relative artifact paths only — not absolute filesystem paths.',
            ],
            $def->promptGuidelines,
        );
        $this->assertSame(\Ineersa\AgentCore\Domain\Tool\ToolExecutionMode::Sequential, $def->executionMode);
    }

    public function testInvokeRejectsWithoutToolContext(): void
    {
        $tool = self::getContainer()->get(AgentRetrieveTool::class);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('requires an active parent run context');
        $tool->__invoke(new AgentRetrieveArgumentsDTO(artifact_id: 'agent_x'));
    }

    public function testMissingIdentifiersAreRejectedByDtoConstraints(): void
    {
        $dto = new AgentRetrieveArgumentsDTO();
        $this->assertNull($dto->trimmedArtifactId());
        $this->assertNull($dto->trimmedAgentRunId());
    }
}
