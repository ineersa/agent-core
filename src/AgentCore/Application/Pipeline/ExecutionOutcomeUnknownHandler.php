<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\ToolBatchIdentity;

final readonly class ExecutionOutcomeUnknownHandler implements RunMessageHandler
{
    public function __construct(private ExecutionOperationStoreInterface $operations)
    {
    }

    public function supports(object $message): bool
    {
        return $message instanceof ExecutionOutcomeUnknown;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof ExecutionOutcomeUnknown) {
            throw new \InvalidArgumentException('Expected an unknown execution notice.');
        }
        if (!$this->operations->unknownNoticePending($message)) {
            return new HandlerResult();
        }
        $action = new ConsumeExecutionUnknownDTO($message);
        $matches = $state->currentOperation?->matchesMessage($message) ?? false;
        foreach ($state->currentToolCalls as $call) {
            if ($state->turnNo === $message->turnNo()
                && $call->batchId === ToolBatchIdentity::fromTurnAndStep($message->turnNo(), $message->stepId())
                && $call->attempt === $message->attempt()
                && isset($state->pendingShellToolCalls[$call->toolCallId])
                && hash('sha256', $state->runId.'|'.$call->toolCallId) === $message->idempotencyKey()) {
                $matches = true;
                break;
            }
        }
        if (!$matches || \in_array($state->status, [RunStatus::Completed, RunStatus::Cancelled], true)) {
            // A delayed old-generation notice must not fail a newer operation.
            return new HandlerResult(nextState: $state, postCommitActions: [$action]);
        }
        $failed = $state->with(['status' => RunStatus::Failed, 'isStreaming' => false, 'streamingMessage' => null, 'errorMessage' => ExecutionOutcomeUnknown::ERROR_MESSAGE]);
        $event = RunEvent::forAppend($state->runId, $state->turnNo, 'agent_end', ['reason' => 'failed', 'error' => ExecutionOutcomeUnknown::ERROR_MESSAGE, 'error_type' => 'execution_outcome_unknown', 'effect_id' => $message->effectId, 'claim_token' => $message->claimToken]);

        return new HandlerResult(nextState: $failed, events: [$event], postCommitActions: [$action]);
    }
}
