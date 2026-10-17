<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Contract\Extension\EssentialAfterTurnHookInterface;
use Ineersa\AgentCore\Domain\Coordination\PublishToolCompletionDTO;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;

/** Captures reference publication, not body delivery or automatic wake. */
final readonly class ToolCompletionPublicationSubscriber implements EssentialAfterTurnHookInterface
{
    public function __construct(private RetainedToolCompletionEnvelopeCodec $codec)
    {
    }

    public function prepareAfterTurnCommit(AfterTurnCommitHookContext $context, int $predecessorSequence): array
    {
        $actions = [];
        foreach ($context->events as $ordinal => $event) {
            $receipt = $event->payload['accepted_completion'] ?? null;
            if (RunEventTypeEnum::ToolExecutionEnd->value !== $event->type || !\is_array($receipt) || true !== ($receipt['detached'] ?? null)) {
                continue;
            }
            $retained = $receipt['retained_message'] ?? null;
            if (!\is_array($retained)) {
                throw new \RuntimeException('Accepted detached receipt lacks its retained envelope.');
            }
            $envelope = $this->codec->denormalize($retained);
            // Only the origin owner publishes. Child-to-root forwarding remains
            // separately gated; no cross-run destination is inferred here.
            if ($envelope->origin->sourceRunId !== $context->runId) {
                throw new \RuntimeException('Completion publication differs from its source owner.');
            }
            $actions[] = new PublishToolCompletionDTO($context->runId, $event->turnNo, $envelope->messageId, $envelope->integrity->payloadFingerprint, $ordinal);
        }

        return $actions;
    }
}
