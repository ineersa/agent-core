<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRetrievalService;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRuntime;

/**
 * Model-visible `agent_retrieve` tool for parent-scoped child-agent artifacts (subagent or fork).
 */
final class AgentRetrieveTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    public function __construct(
        private readonly AgentArtifactRetrievalService $retrievalService,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): string
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
            name: 'agent_retrieve',
            description: 'Retrieve a completed or failed child-agent artifact handoff (subagent or fork), metadata, or bounded event/history summary from the current parent session.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'artifact_id' => [
                        'type' => 'string',
                        'description' => 'Child artifact id (e.g. agent_abc123) within the current parent session.',
                    ],
                    'agent_run_id' => [
                        'type' => 'string',
                        'description' => 'Child AgentCore run id (UUID) for the child run.',
                    ],
                    'mode' => [
                        'type' => 'string',
                        'enum' => ['handoff', 'metadata', 'events', 'history', 'debug'],
                        'description' => 'Output mode. Default handoff.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 100,
                        'description' => 'Max rows for events/history modes (default 20).',
                    ],
                ],
                'required' => [],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'agent_retrieve artifact_id=<id>|agent_run_id=<uuid> [mode=handoff|metadata|events|history|debug] [limit=N] — load child-agent handoff or bounded artifact summary',
            promptGuidelines: [
                'Use agent_retrieve when parallel child summaries were truncated, a child failed/cancelled/timed out, or you need metadata/events/history/debug — not for successful single-mode subagent handoffs already returned inline.',
                'Provide artifact_id and/or agent_run_id from the current parent session only; cross-parent retrieval is rejected.',
                'Default mode handoff returns stored handoff.md; redundant after a successful single-mode subagent/fork unless you need to re-fetch or inspect metadata.',
                'Use metadata for status, timestamps, and counts without raw message or tool output.',
                'Use events or history for bounded debugging summaries; payloads and prompts are omitted by default.',
                'Use debug for relative artifact paths only — not absolute filesystem paths.',
            ],
        );
    }
}
