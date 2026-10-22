<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;

final readonly class BackgroundChildInvocationDTO extends AbstractAgentBusMessage
{
    public function __construct(string $runId, public string $lifecycleId, public string $toolCallId)
    {
        parent::__construct($runId, 0, $lifecycleId, 1, 'background-child-'.$lifecycleId);
    }
}
