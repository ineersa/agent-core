<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionEnvelope;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailure;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionFailureCodeEnum;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationAcceptedCompletionIdentity;

/** Verifies a retained reference without resolving or hashing any referenced body. */
final readonly class ToolCompletionDeliveryVerifier
{
    public function __construct(
        private EventStoreInterface $events,
        private RetainedToolCompletionEnvelopeCodec $codec,
        private OwningRootSessionResolverInterface $owningRoots,
    ) {
    }

    /**
     * The caller supplies the locked recipient identity, not an identity from tool output.
     * Recipient dedupe must precede this lookup. Verification is not delivery or wake permission.
     */
    public function verify(string $destinationRunId, ToolCompletionDeliveryReferenceDTO $reference): RetainedToolCompletionEnvelope
    {
        // Cross-run forwarding is product-gated. A reference is not a scope capability.
        if (false !== strpbrk($destinationRunId, '/\\') || $destinationRunId !== $reference->destinationRunId || $reference->sourceEvent->runId !== $destinationRunId) {
            throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Completion delivery is outside the recipient owner scope.');
        }
        $source = $reference->sourceEvent;
        foreach ($this->events->rangeFor($source->runId, $source->seq, $source->seq) as $event) {
            if ($event->runId !== $source->runId || $event->seq !== $source->seq || $event->turnNo !== $source->turnNo || $event->type !== $source->type) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Completion source event identity does not match its reference.');
            }
            $receipt = $event->payload['accepted_completion'] ?? null;
            if (!\is_array($receipt) || true !== ($receipt['detached'] ?? null)) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Unsupported, 'Only accepted background completions can enter automatic delivery.');
            }
            $retained = $receipt['retained_message'] ?? null;
            if (!\is_array($retained)) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Completion receipt has no retained envelope.');
            }
            $envelope = $this->codec->denormalize($retained);
            $origin = $envelope->origin;
            if ($origin->controllerRunId !== $this->owningRoots->resolveOwningRootSessionId($destinationRunId) || $origin->sourceRunId !== $source->runId) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::ForeignScope, 'Completion origin does not belong to the recipient root.');
            }
            $acceptedSource = $origin->acceptedSourceEventReference;
            if (null === $acceptedSource
                || $acceptedSource->runId !== $source->runId
                || $acceptedSource->seq !== $source->seq
                || $acceptedSource->turnNo !== $source->turnNo
                || $acceptedSource->type !== $source->type
                || ($receipt['completion_id'] ?? null) !== $envelope->messageId
                || ($receipt['origin_run_id'] ?? null) !== $origin->sourceRunId
                || ($receipt['origin_turn_no'] ?? null) !== $origin->originTurnNo
                || ($receipt['origin_step_id'] ?? null) !== $origin->originStepId
                || ($receipt['tool_call_id'] ?? null) !== $origin->toolCallId
                || ($receipt['invocation_id'] ?? null) !== $origin->invocationId
                || $envelope->messageId !== ToolInvocationAcceptedCompletionIdentity::completionId($origin->sourceRunId, $origin->originTurnNo, $origin->originStepId, $origin->toolCallId)
            ) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::BodyCorrupt, 'Completion receipt and retained origin disagree.');
            }
            if ($reference->messageId !== $envelope->messageId
                || $reference->payloadFingerprint !== $envelope->integrity->payloadFingerprint
                || $this->codec->payloadFingerprint($envelope) !== $reference->payloadFingerprint
            ) {
                throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::Conflict, 'Completion delivery conflicts with canonical retained evidence.');
            }

            return $envelope;
        }

        throw new RetainedToolCompletionFailure(RetainedToolCompletionFailureCodeEnum::NotFound, 'Completion source is not in committed canonical history.');
    }
}
