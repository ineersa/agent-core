<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ToolExecutionAuthorization;
use Ineersa\AgentCore\Domain\Coordination\ConsumeToolExecutionUnknownDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown;
use Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;

final readonly class ToolExecutionOutcomeUnknownHandler implements RunMessageHandler
{
    public function __construct(private ToolExecutionAuthorization $authorization)
    {
    }

    public function supports(object $message): bool
    {
        return $message instanceof ToolExecutionOutcomeUnknown;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof ToolExecutionOutcomeUnknown) {
            throw new \InvalidArgumentException('Expected an unknown tool execution notice.');
        }
        if (!$this->authorization->unknownNoticePending($message)) {
            return new HandlerResult();
        }
        $actions = [new ConsumeToolExecutionUnknownDTO($message)];
        if ($state->turnNo !== $message->turnNo() || $state->activeStepId !== $message->stepId()
            || !\array_key_exists($message->toolCallId, $state->pendingToolCalls)
            || !$this->authorization->matchesCurrentInvocation($message)
            || \in_array($state->status, [RunStatus::Completed, RunStatus::Cancelled], true)) {
            return new HandlerResult(nextState: $state, postCommitActions: $actions);
        }
        $failed = $state->with(['status' => RunStatus::Failed, 'isStreaming' => false, 'streamingMessage' => null, 'errorMessage' => ExecutionOutcomeUnknown::ERROR_MESSAGE]);
        $event = RunEvent::forAppend($state->runId, $state->turnNo, 'agent_end', ['reason' => 'failed', 'error' => ExecutionOutcomeUnknown::ERROR_MESSAGE, 'error_type' => 'execution_outcome_unknown', 'tool_call_id' => $message->toolCallId, 'claim_token' => $message->claimToken]);

        return new HandlerResult(nextState: $failed, events: [$event], postCommitActions: $actions);
    }
}
