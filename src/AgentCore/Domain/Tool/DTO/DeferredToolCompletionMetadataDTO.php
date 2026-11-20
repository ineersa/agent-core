<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\DTO;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionStatusEnum;
use Ineersa\AgentCore\Contract\Tool\DeferredToolInterruptionReasonEnum;

/** Identity and status readable after correlation data has been cleaned. */
final readonly class DeferredToolCompletionMetadataDTO
{
    public function __construct(
        public string $deferredId,
        public ToolPayloadReferenceDTO $reference,
        public DeferredToolCompletionStatusEnum $status,
        public ?\DateTimeImmutable $readyAt = null,
        public ?\DateTimeImmutable $backgroundedAt = null,
        public ?ToolPayloadReferenceDTO $acceptedResultReference = null,
        public ?DeferredToolInterruptionReasonEnum $interruptionReason = null,
        public bool $continuationRequired = false,
    ) {
    }
}
