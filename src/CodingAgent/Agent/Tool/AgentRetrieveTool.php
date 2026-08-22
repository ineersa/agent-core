<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRetrievalService;
use Ineersa\CodingAgent\Agent\Artifact\AgentRetrieveArgumentsDTO;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolRuntime;

/**
 * Model-visible `agent_retrieve` tool for parent-scoped subagent artifacts.
 */
final class AgentRetrieveTool implements HatfieldToolProviderInterface
{
    public const string NAME = 'agent_retrieve';

    public const string DESCRIPTION = 'Retrieve a completed or failed subagent artifact handoff, metadata, or bounded event/history summary from the current parent session.';

    public function __construct(
        private readonly AgentArtifactRetrievalService $retrievalService,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
    ) {
    }

    public function __invoke(AgentRetrieveArgumentsDTO $arguments): string
    {
        return $this->toolRuntime->run(function () use ($arguments): string {
            $context = $this->contextAccessor->current();
            if (null === $context) {
                throw new ToolCallException('The agent_retrieve tool requires an active parent run context.', retryable: false);
            }

            $parentRunId = $context->runId();
            if ('' === $parentRunId) {
                throw new ToolCallException('agent_retrieve requires a valid parent run ID.', retryable: false);
            }

            return $this->retrievalService->retrieve($parentRunId, $arguments);
        });
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: self::NAME,
            description: self::DESCRIPTION,
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'agent_retrieve artifact_id=<id>|agent_run_id=<uuid> [mode] [limit=N] — retrieve a subagent artifact',
            promptGuidelines: [
                'Use agent_retrieve when parallel subagent summaries were truncated, a child failed/cancelled/timed out, or you need metadata/events/history/debug — not for successful single-mode subagent handoffs already returned inline.',
                'Provide artifact_id and/or agent_run_id from the current parent session only; cross-parent retrieval is rejected.',
                'Use metadata for status, timestamps, and counts without raw message or tool output.',
                'Use events or history for bounded debugging summaries; payloads and prompts are omitted by default.',
                'Use mode=handoff_history to list archived prior handoffs, or pass index=<n> to fetch one archived body. mode=handoff remains the latest handoff only.',
                'Use debug for relative artifact paths only — not absolute filesystem paths.',
            ],
        );
    }
}
