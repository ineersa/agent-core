<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\DTO;

/** Identity and status readable after correlation data has been cleaned. */
final readonly class DeferredToolCompletionMetadataDTO
{
    public function __construct(
        public string $deferredId,
        public ToolPayloadReferenceDTO $reference,
        public string $status,
        public ?\DateTimeImmutable $readyAt = null,
        public ?\DateTimeImmutable $backgroundedAt = null,
        public ?ToolPayloadReferenceDTO $acceptedResultReference = null,
    ) {
    }
}
