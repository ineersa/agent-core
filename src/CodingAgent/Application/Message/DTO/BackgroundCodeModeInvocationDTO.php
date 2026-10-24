<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class BackgroundCodeModeInvocationDTO extends AbstractAgentBusMessage
{
    public function __construct(string $runId, #[Assert\Uuid] #[Assert\NotBlank] public string $deferredId, #[Assert\NotBlank] public string $toolCallId, public bool $stop = false)
    {
        parent::__construct($runId, 0, $deferredId, 1, 'background-code-mode-'.$deferredId);
    }
}
