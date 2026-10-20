<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;

final readonly class DeliverBackgroundCompletionDTO extends AbstractAgentBusMessage
{
    public function __construct(string $runId, public string $identity, public string $text)
    {
        parent::__construct($runId, 0, $identity, 1, $identity);
    }
}
