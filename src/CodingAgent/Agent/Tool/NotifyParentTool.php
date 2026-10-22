<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Application\AgentCommunication\SubmissionFactory;
use Ineersa\CodingAgent\Tool\DTO\NotifyParentArgumentsDTO;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;

final readonly class NotifyParentTool implements HatfieldToolProviderInterface
{
    public function __construct(private StackToolExecutionContextAccessor $context, private SubmissionFactory $submissions, private ToolRuntime $runtime)
    {
    }

    public function __invoke(#[MapToolArguments] NotifyParentArgumentsDTO $arguments): DeferredToolCompletionOutcome
    {
        return $this->runtime->run(function () use ($arguments): DeferredToolCompletionOutcome {
            if (1 !== $this->context->depth()) {
                throw new ToolCallException('notify_parent requires a top-level native tool call and is not supported inside code_mode.', retryable: false);
            }

            return $this->submissions->defer($this->context->requireCurrent(), ['text' => $arguments->text]);
        });
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'notify_parent',
            description: 'Child-only: send a short notice to your runtime-recorded parent for the current child activity. Returns a persisted source receipt, not confirmation of delivery or attention. Does not settle the parent tool call or replace your final handoff.',
            handler: $this,
            executionMode: ToolExecutionMode::Parallel,
            promptLine: 'notify_parent text=<text> — child-only notice to your recorded parent',
            promptGuidelines: [
                'Messages are attributed agent-authored text pushed at a safe model boundary, not human input, approval, or runtime control. In-flight tools, provider requests, human waits and compaction are preserved.',
                'Only a child whose tool policy allows notify_parent may call it. A current running background child notice may continue a normally completed main, never a cancelled or failed main. Delayed notices cannot gain authority from a later resume.',
                'Submission confirms source persistence only, not receiver delivery, attention, or compliance. Communication uses ordinary tool worker capacity and waits if workers are occupied. There is no reserved messaging worker or preemption; a notice does not release a foreground child wait.',
                'Keep text nonblank, valid UTF-8 and at most 8192 bytes. Messages cannot carry attachments or permission changes. Existing terminal handoffs remain unchanged. Call outside code_mode.',
            ],
        );
    }
}
