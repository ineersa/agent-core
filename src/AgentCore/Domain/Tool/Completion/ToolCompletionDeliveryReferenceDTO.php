<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/** Internal recipient instruction pointing to canonical evidence, never its body. */
final readonly class ToolCompletionDeliveryReferenceDTO
{
    public function __construct(
        public string $destinationRunId,
        public string $messageId,
        public RetainedToolCompletionSourceEventReference $sourceEvent,
        public string $payloadFingerprint,
        public ?ToolCompletionDeliveryFenceDTO $deliveryFence = null,
    ) {
        if ('' === trim($destinationRunId) || '' === trim($messageId)
            || \strlen($destinationRunId) > 255 || \strlen($messageId) > 255 || \strlen($sourceEvent->runId) > 255) {
            throw new \InvalidArgumentException('Completion delivery requires destination and message identities.');
        }
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $payloadFingerprint)) {
            throw new \InvalidArgumentException('Completion delivery requires a SHA-256 payload fingerprint.');
        }
        if ('tool_execution_end' !== $sourceEvent->type) {
            throw new \InvalidArgumentException('Completion delivery must reference a terminal tool receipt.');
        }
    }
}
