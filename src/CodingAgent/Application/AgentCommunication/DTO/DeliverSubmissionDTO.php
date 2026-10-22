<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication\DTO;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;

final readonly class DeliverSubmissionDTO extends AbstractAgentBusMessage
{
    public function __construct(string $runId, public string $sourceRunId, public string $identity)
    {
        parent::__construct($runId, 0, $identity, 1, $identity);
    }
}
