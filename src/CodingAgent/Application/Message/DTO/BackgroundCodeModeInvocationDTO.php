<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;

final readonly class BackgroundCodeModeInvocationDTO extends AbstractAgentBusMessage
{
    public function __construct(string $runId, public string $deferredId, public string $toolCallId)
    {
        parent::__construct($runId, 0, $deferredId, 1, 'background-code-mode-'.$deferredId);
    }
}
