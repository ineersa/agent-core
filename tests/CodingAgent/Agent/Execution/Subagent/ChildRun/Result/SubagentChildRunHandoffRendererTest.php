<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Result;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunHandoffRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: a terminally failed child after useful work must produce a usable
 * partial handoff from durable child state (session 37 fork handoff regression).
 *
 * @covers \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunHandoffRenderer
 */
final class SubagentChildRunHandoffRendererTest extends TestCase
{
    public function testFailedHandoffIncludesPartialContextFromChildState(): void
    {
        $renderer = new SubagentChildRunHandoffRenderer();
        $childState = new RunState(
            runId: 'a5089241-a55a-5794-9353-b7cc43cb30fc',
            status: RunStatus::Failed,
            turnNo: 295,
            lastSeq: 297,
            errorMessage: 'Codex WebSocket request frame could not be sent.',
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'task']]),
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Found root cause in CodexWebSocketModelClient send path.']]),
            ],
        );

        $markdown = $renderer->buildHandoffMarkdown(
            status: AgentArtifactStatusEnum::Failed,
            summary: 'Codex WebSocket request frame could not be sent.',
            failureReason: 'Codex WebSocket request frame could not be sent.',
            needsClarification: null,
            artifactId: 'agent_a7f0997ff6034869',
            agentName: 'fork',
            agentRunId: 'a5089241-a55a-5794-9353-b7cc43cb30fc',
            childState: $childState,
        );

        $this->assertStringContainsString('Status: failed', $markdown);
        $this->assertStringContainsString('Codex WebSocket request frame could not be sent.', $markdown);
        $this->assertStringContainsString('## Partial context', $markdown);
        $this->assertStringContainsString('turn_no: 295', $markdown);
        $this->assertStringContainsString('message_count: 2', $markdown);
        $this->assertStringContainsString('Found root cause in CodexWebSocketModelClient send path.', $markdown);
        $this->assertStringContainsString('Use agent_retrieve (metadata/events/history) for more child details.', $markdown);
    }
}
