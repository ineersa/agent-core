<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Contract\CanonicalSequenceBoundActionInterface;
use Ineersa\AgentCore\Domain\Message\ReceiveToolCompletion;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionSourceEventReference;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;

/** Fixes source location from actual allocation before post-commit publication. */
final readonly class PublishToolCompletionDTO implements CanonicalSequenceBoundActionInterface
{
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $messageId,
        public string $payloadFingerprint,
        public int $sourceEventOrdinal,
    ) {
        if ($sourceEventOrdinal < 0 || $turnNo < 0 || '' === trim($runId) || '' === trim($messageId)
            || \strlen($runId) > 255 || \strlen($messageId) > 255
            || 1 !== preg_match('/^[a-f0-9]{64}$/D', $payloadFingerprint)) {
            throw new \InvalidArgumentException('Invalid captured completion publication.');
        }
    }

    public function bindCanonicalSequences(array $sequences): object
    {
        $sequence = $sequences[$this->sourceEventOrdinal] ?? throw new \RuntimeException('Completion source ordinal is outside its transition.');
        $reference = new ToolCompletionDeliveryReferenceDTO($this->runId, $this->messageId, new RetainedToolCompletionSourceEventReference($this->runId, $sequence, $this->turnNo), $this->payloadFingerprint);

        return new DispatchCoordinationMessageDTO(new ReceiveToolCompletion($reference));
    }
}
