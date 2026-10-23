<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Application\Deferred\DeferredCallerAcknowledgementService;
use Ineersa\CodingAgent\Application\Message\DTO\BackgroundChildInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\MarkChildBackgroundedDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;

final readonly class BackgroundChildInvocationHandler implements RunMessageHandler
{
    public function __construct(
        private DeferredSubagentBatchRepository $batches,
        private DeferredCallerAcknowledgementService $acknowledgements,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof BackgroundChildInvocationDTO;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof BackgroundChildInvocationDTO) {
            throw new \InvalidArgumentException('Unsupported child background message.');
        }

        return $this->detach($message, $state);
    }

    private function detach(BackgroundChildInvocationDTO $message, RunState $state): HandlerResult
    {
        if (false !== ($state->pendingToolCalls[$message->toolCallId] ?? null)) {
            // Repeated requests and calls whose wait already settled are no-ops.
            return new HandlerResult();
        }
        if (null === $this->batches->findBackgroundCandidate($state->runId, $message->lifecycleId, $message->toolCallId)) {
            // The picker is a snapshot: completion/interruption may have won.
            return new HandlerResult();
        }
        $batch = $this->batches->findByParentRunAndToolCall($state->runId, $message->toolCallId);
        if (null === $batch) {
            throw new \LogicException('Eligible child background batch is missing.');
        }
        if ($batch->parentTurnNo !== $state->turnNo) {
            // A request from an older owner turn cannot detach current work.
            return new HandlerResult();
        }
        if (1 !== \count($batch->children)) {
            throw new \LogicException('Child invocation requires exactly one reserved child.');
        }
        $child = $batch->children[0];
        $active = null === $child->childLifecycleProjection || !$child->childLifecycleProjection->childStatus->isTerminal();
        if (!$active) {
            // Natural completion won the race; let the existing lifecycle deliver it.
            return new HandlerResult(postCommitActions: [new DeliverDeferredSubagentBatchLifecycleMessage($batch->lifecycleId)]);
        }
        $identity = \sprintf('%s (agent_id: %s, run_id: %s)', $child->agentName, $child->artifactId, $child->childRunId);
        $result = $this->acknowledgements->acknowledge(
            $batch->lifecycleId,
            $state,
            [['type' => 'text', 'text' => "Child invocation moved to background. The same child keeps running under its original deadline. Its handoff will arrive as a follow-up.\n".$identity]],
            ['backgrounded' => true],
        );
        if ([] === $result->events) {
            return $result;
        }

        // Shared acknowledgement lands before activity projection so terminal
        // routing can observe acknowledged_at when lifecycle delivery runs.
        return new HandlerResult($result->nextState, $result->events, $result->effects, $result->postCommitEffects, [
            ...$result->postCommitActions,
            new DispatchLocalMetadataMessageDTO(new MarkChildBackgroundedDTO($batch->lifecycleId, new \DateTimeImmutable())),
        ]);
    }
}
