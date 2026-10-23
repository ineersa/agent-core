<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Deferred;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Tool\DeferredToolInvocationFence;
use Ineersa\CodingAgent\Application\Deferred\DTO\MarkDeferredAcknowledgedDTO;

/** Shared caller acknowledgement for deferred child and code-mode ownership. */
final readonly class DeferredCallerAcknowledgementService
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferred,
        private ToolBatchStoreInterface $batches,
        private ToolPayloadStoreInterface $payloads,
        private DeferredToolInvocationFence $fence,
        private ToolCallResultHandler $results,
    ) {
    }

    /**
     * Resolve only the original caller while the deferred registration stays pending.
     *
     * @param list<array{type: string, text: string}> $content
     * @param array<string, mixed>|null               $details
     */
    public function acknowledge(string $deferredId, RunState $state, array $content, ?array $details = null): HandlerResult
    {
        if (null !== $this->deferred->acknowledgedAt($deferredId)) {
            return new HandlerResult();
        }
        if (null !== $this->deferred->findAcceptedResultReferenceByDeferredId($deferredId)) {
            // First terminal acceptance already owns settlement; ACK must not race it.
            return new HandlerResult();
        }
        $correlation = $this->deferred->findByDeferredId($deferredId);
        if (null === $correlation || $correlation->runId !== $state->runId) {
            return new HandlerResult();
        }
        if (false !== ($state->pendingToolCalls[$correlation->toolCallId] ?? null)) {
            return new HandlerResult();
        }
        $reference = $this->deferred->findInvocationReferenceByDeferredId($deferredId);
        if (null === $reference) {
            throw new \RuntimeException('Pending deferred registration is missing its invocation reference.');
        }
        $call = $this->payloads->read($reference);
        $live = $this->batches->loadCall($state->runId, $state->turnNo, (string) $state->activeStepId, $correlation->toolCallId);
        $liveReference = $this->batches->loadCallReference($state->runId, $state->turnNo, (string) $state->activeStepId, $correlation->toolCallId);
        if (!$call instanceof ExecuteToolCall
            || $call->turnNo() !== $state->turnNo
            || $call->stepId() !== $state->activeStepId
            || !$this->fence->sameInvocation($call, $live)
            || (array) $reference !== (array) $liveReference) {
            return new HandlerResult();
        }
        $ack = ToolCallResultFactory::fromDeferredCorrelationAndCompletion($correlation, $content, $details, false, null);
        if (!$this->fence->matchesResult($ack, $call)) {
            throw new \RuntimeException('Deferred correlation changed before acknowledgement.');
        }
        $result = $this->results->acknowledge($ack, $state);
        if ([] === $result->events) {
            return $result;
        }

        return new HandlerResult(
            $result->nextState,
            $result->events,
            $result->effects,
            $result->postCommitEffects,
            [new DispatchLocalMetadataMessageDTO(new MarkDeferredAcknowledgedDTO($deferredId)), ...$result->postCommitActions],
        );
    }
}
