<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Application\Message\DTO\BackgroundChildInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\MarkChildBackgroundedDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;

final readonly class BackgroundChildInvocationHandler implements RunMessageHandler
{
    public function __construct(private DeferredSubagentBatchRepository $batches, private DeferredToolCompletionRepositoryInterface $deferred, private ToolCallResultHandler $results)
    {
    }

    public function supports(object $message): bool
    {
        return $message instanceof BackgroundChildInvocationDTO;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        \assert($message instanceof BackgroundChildInvocationDTO);
        $batch = $this->batches->findByParentRunAndToolCall($state->runId, $message->toolCallId);
        if (null === $batch || $batch->lifecycleId !== $message->lifecycleId || null !== $batch->backgroundedAt
            || null !== $batch->terminalCompletionEnqueuedAt || null !== $batch->interruptionKind
            || DeferredSubagentBatchLaunchStatusEnum::Launched !== $batch->launchStatus
            || $batch->parentTurnNo !== $state->turnNo || false !== ($state->pendingToolCalls[$message->toolCallId] ?? null)) {
            return new HandlerResult();
        }
        $correlation = $this->deferred->findByDeferredId($batch->lifecycleId);
        if (null === $correlation || !\in_array($correlation->toolName, ['subagent', 'fork'], true)
            || 'pending' !== $this->deferred->status($batch->lifecycleId)) {
            return new HandlerResult();
        }
        $active = array_any($batch->children, static fn ($child): bool => null === $child->childLifecycleProjection || !$child->childLifecycleProjection->childStatus->isTerminal());
        if (!$active) {
            // Natural completion won the race; let the existing lifecycle deliver it.
            return new HandlerResult(postCommitActions: [new DeliverDeferredSubagentBatchLifecycleMessage($batch->lifecycleId)]);
        }
        $identities = array_map(static fn ($child): string => \sprintf('%s (agent_id: %s, run_id: %s)', $child->agentName, $child->artifactId, $child->childRunId), $batch->children);
        $ack = ToolCallResultFactory::fromDeferredCorrelationAndCompletion($correlation, [['type' => 'text', 'text' => "Child invocation moved to background. The same children keep running under their original deadline. Their aggregate handoff will arrive as a follow-up.\n".implode("\n", $identities)]], null, false, null);
        $result = $this->results->handle($ack, $state);

        // Reuse the canonical result/batch transition and its recoverable actions.
        // Record wait detachment before normal batch finalization and successors.
        return new HandlerResult($result->nextState, $result->events, $result->effects, $result->postCommitEffects, [
            new MarkChildBackgroundedDTO($batch->lifecycleId, new \DateTimeImmutable()),
            ...$result->postCommitActions,
            new DeliverDeferredSubagentBatchLifecycleMessage($batch->lifecycleId),
        ]);
    }
}
