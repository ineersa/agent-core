<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode\DTO;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;

final readonly class StopCodeModeDTO extends AbstractAgentBusMessage
{
    public function __construct(string $runId, public string $identity, public string $toolCallId)
    {
        parent::__construct($runId, 0, $identity, 1, $identity.'-stop');
    }
}
