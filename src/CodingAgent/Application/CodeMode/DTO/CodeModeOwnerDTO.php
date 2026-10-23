<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode\DTO;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;

final readonly class CodeModeOwnerDTO extends AbstractAgentBusMessage
{
    public function __construct(string $runId, public string $identity, public string $toolCallId, public bool $acknowledge = false)
    {
        parent::__construct($runId, 0, $identity, 1, $identity.($acknowledge ? '-ack' : '-terminal'));
    }
}
