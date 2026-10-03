<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Result;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunHandoffRenderer;
use Ineersa\CodingAgent\Session\History\RunPresentationDTO;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: a terminally failed child after useful work must produce a usable
 * partial handoff from durable child state (session 37 fork handoff regression).
 *
 * @covers \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunHandoffRenderer
 */
final class SubagentChildRunHandoffRendererTest extends TestCase
{
    public function testInlineHandoffLimitCountsCharactersIncludingTheEnvelope(): void
    {
        $renderer = new SubagentChildRunHandoffRenderer();
        $prefix = "Subagent scout completed.\nArtifact: agent_123\n\nHandoff:\n\n";
        $handoff = str_repeat('é', 50000 - \strlen($prefix));

        $this->assertSame($prefix.$handoff, $renderer->formatCompletedResult('scout', 'agent_123', $handoff));
        $short = $renderer->formatCompletedResult('scout', 'agent_123', $handoff.'x');
        $this->assertStringContainsString('exceeds 50,000 characters', $short);
        $this->assertStringContainsString('Artifact: agent_123', $short);
        $this->assertStringContainsString('agent_retrieve', $short);
        $this->assertStringNotContainsString('é', $short);
        $this->assertLessThan(50000, \strlen($short));

        // The durable artifact remains complete even when inline delivery is short.
        $markdown = $renderer->buildHandoffMarkdown(AgentArtifactStatusEnum::Completed, $handoff.'x', null, null);
        $this->assertStringContainsString($handoff.'x', $markdown);
    }

    public function testOversizedFailureAndTimeoutKeepArtifactReferences(): void
    {
        $renderer = new SubagentChildRunHandoffRenderer();
        $large = str_repeat('x', 50001);
        foreach ([
            $renderer->formatFailedResult('scout', 'agent_failed', $large),
            $renderer->formatTimeoutResult('scout', 60, $large, 'agent_timeout'),
        ] as $text) {
            $this->assertStringContainsString('Inline handoff omitted', $text);
            $this->assertStringContainsString('Artifact: agent_', $text);
            $this->assertStringNotContainsString($large, $text);
        }
    }

    public function testFailedHandoffIncludesPartialContextFromChildState(): void
    {
        $renderer = new SubagentChildRunHandoffRenderer();
        $childPresentation = new RunPresentationDTO(RunStatus::Failed, 295, 297, 2, 2, 0, null, 'Found root cause in CodexWebSocketModelClient send path.', true, true, 1, []);

        $markdown = $renderer->buildHandoffMarkdown(
            status: AgentArtifactStatusEnum::Failed,
            summary: 'Codex WebSocket request frame could not be sent.',
            failureReason: 'Codex WebSocket request frame could not be sent.',
            needsClarification: null,
            artifactId: 'agent_a7f0997ff6034869',
            agentName: 'fork',
            agentRunId: 'a5089241-a55a-5794-9353-b7cc43cb30fc',
            childPresentation: $childPresentation,
        );

        $this->assertStringContainsString('Status: failed', $markdown);
        $this->assertStringContainsString('Codex WebSocket request frame could not be sent.', $markdown);
        $this->assertStringContainsString('## Partial context', $markdown);
        $this->assertStringContainsString('turn_no: 295', $markdown);
        $this->assertStringContainsString('message_count: 2', $markdown);
        $this->assertStringContainsString('Found root cause in CodexWebSocketModelClient send path.', $markdown);
        $this->assertStringContainsString('Use agent_retrieve (metadata/events/history) for more child details.', $markdown);
    }

    public function testPartialContextTruncationKeepsValidUtf8(): void
    {
        $box = "\u{2500}";
        $this->assertTrue(mb_check_encoding($box, 'UTF-8'));
        $renderer = new SubagentChildRunHandoffRenderer();
        $excerpt = str_repeat($box, 900);
        $childPresentation = new RunPresentationDTO(RunStatus::Failed, 1, 1, 1, 1, 0, null, $excerpt, true, true, 1, []);

        $markdown = $renderer->buildHandoffMarkdown(
            status: AgentArtifactStatusEnum::Failed,
            summary: 'failed',
            failureReason: 'failed',
            needsClarification: null,
            artifactId: 'agent_utf8',
            agentName: 'scout',
            agentRunId: 'a5089241-a55a-5794-9353-b7cc43cb30fc',
            childPresentation: $childPresentation,
        );

        $this->assertTrue(mb_check_encoding($markdown, 'UTF-8'));
        $this->assertStringContainsString('...', $markdown);
        $this->assertStringContainsString($box, $markdown);
    }

    public function testPendingActivityAndExcerptDecisionUsePresentationScalars(): void
    {
        $presentation = new RunPresentationDTO(RunStatus::Cancelled, 3, 8, 8, 4, 1, str_repeat('p', 121), 'Cancelled text after leading whitespace', true, true, 1, []);
        $renderer = new SubagentChildRunHandoffRenderer();
        $markdown = $renderer->buildHandoffMarkdown(AgentArtifactStatusEnum::Cancelled, null, null, null, childPresentation: $presentation);
        $this->assertStringContainsString('pending tool_call: '.str_repeat('p', 117).'...', $markdown);
        $this->assertStringContainsString('## Last assistant excerpt', $markdown);
        $this->assertStringContainsString('Cancelled text after leading whitespace', $markdown);
        $this->assertStringContainsString('pending_tool_calls: 1', $markdown);

        $suppressed = new RunPresentationDTO(RunStatus::Cancelled, 3, 8, 8, 4, 0, null, 'nonempty suppressed excerpt', false, false, 1, []);
        $markdown = $renderer->buildHandoffMarkdown(AgentArtifactStatusEnum::Cancelled, null, null, null, childPresentation: $suppressed);
        $this->assertStringContainsString('last_known_activity: run status cancelled', $markdown);
        $this->assertStringNotContainsString('## Last assistant excerpt', $markdown);
    }
}
