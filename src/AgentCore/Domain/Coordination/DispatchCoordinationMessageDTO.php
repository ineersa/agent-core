<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\CompactRun;

final readonly class DispatchCoordinationMessageDTO
{
    public function __construct(public AdvanceRun|CompactRun|ApplyCommand $message)
    {
    }
}
