<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown;

final readonly class ConsumeToolExecutionUnknownDTO
{
    public function __construct(public ToolExecutionOutcomeUnknown $notice)
    {
    }
}
