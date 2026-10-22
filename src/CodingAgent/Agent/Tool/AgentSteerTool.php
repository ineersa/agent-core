<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionFactory;
use Ineersa\CodingAgent\Tool\DTO\AgentSteerArgumentsDTO;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;

final readonly class AgentSteerTool implements HatfieldToolProviderInterface
{
    public function __construct(private StackToolExecutionContextAccessor $context, private SubmissionFactory $submissions, private ToolRuntime $runtime)
    {
    }

    public function __invoke(#[MapToolArguments] AgentSteerArgumentsDTO $arguments): DeferredToolCompletionOutcome
    {
        return $this->runtime->run(function () use ($arguments): DeferredToolCompletionOutcome {
            if (1 !== $this->context->depth()) {
                throw new ToolCallException('agent_steer requires a top-level native tool call and is not supported inside code_mode.', retryable: false);
            }

            return $this->submissions->defer($this->context->requireCurrent(), ['text' => $arguments->text, 'artifact_id' => $arguments->artifact_id, 'agent_run_id' => $arguments->agent_run_id]);
        });
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'agent_steer',
            description: 'Send short guidance to one current active direct subagent or fork, including its current agent_resume activity. Requires exactly one child handle. Returns a persisted source receipt, not confirmation of delivery or attention. Never resumes a terminal child.',
            handler: $this,
            executionMode: ToolExecutionMode::Parallel,
            promptLine: 'agent_steer text=<text> artifact_id=<id>|agent_run_id=<uuid> — guide one active direct child',
            promptGuidelines: [
                'Messages are attributed agent-authored text pushed at a safe model boundary, not human input, approval, or runtime control. In-flight tools, provider requests, human waits and compaction are preserved.',
                'Only current direct parent-child activities are authorized. A delayed message cannot become guidance for a later resume. Use agent_resume explicitly for a terminal child.',
                'Submission confirms source persistence only, not receiver delivery, attention, or compliance. Communication uses ordinary tool worker capacity and waits if workers are occupied. There is no reserved messaging worker or preemption; a notice does not release a foreground child wait.',
                'Keep text nonblank, valid UTF-8 and at most 8192 bytes. Messages cannot carry attachments or permission changes. Existing terminal handoffs remain unchanged. Call outside code_mode.',
            ],
        );
    }
}
