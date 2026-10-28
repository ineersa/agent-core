<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class DeliverBackgroundCompletionDTO extends AbstractAgentBusMessage
{
    /** @param array<string, mixed> $origin Host-recorded producer identities, retained with the first admission. */
    public function __construct(
        string $runId,
        #[Assert\NotBlank, Assert\Length(max: 255)] public string $identity,
        public string $text,
        #[Assert\NotBlank(allowNull: true)] public ?string $sourceRunId = null,
        #[Assert\NotBlank(allowNull: true)] public ?string $deferredId = null,
        public ?ToolPayloadReferenceDTO $invocation = null,
        public array $origin = [],
        #[Assert\Positive] public ?int $processRecordId = null,
        public ?string $outputTail = null,
    ) {
        parent::__construct($runId, 0, $identity, 1, $identity);
    }
}
