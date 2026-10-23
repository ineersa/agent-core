<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class DeliverBackgroundCompletionDTO extends AbstractAgentBusMessage
{
    public function __construct(
        string $runId,
        #[Assert\NotBlank, Assert\Length(max: 255)] public string $identity,
        public string $text,
        #[Assert\NotBlank(allowNull: true)] public ?string $sourceRunId = null,
        public bool $explicitContinuation = false,
    ) {
        parent::__construct($runId, 0, $identity, 1, $identity);
    }
}
