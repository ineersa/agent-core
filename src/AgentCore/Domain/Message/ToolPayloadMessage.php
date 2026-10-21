<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Symfony\Component\Validator\Constraints as Assert;

/** The only transported representation of tool invocation and outcome data. */
final readonly class ToolPayloadMessage extends AbstractAgentBusMessage
{
    public function __construct(
        #[Assert\Valid]
        public ToolPayloadReferenceDTO $reference,
        #[Assert\Length(max: 255)]
        public ?string $deferredId = null,
    ) {
        parent::__construct($reference->runId, $reference->turnNo, $reference->stepId, $reference->attempt, $reference->idempotencyKey);
    }
}
