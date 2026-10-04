<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;

final readonly class ExecutionResultDispositionDTO
{
    public function __construct(public DurableExecutionResult $result, public string $disposition)
    {
        if (!\in_array($disposition, ['Consumed', 'Stale'], true)) {
            throw new \InvalidArgumentException('Invalid execution result disposition.');
        }
    }
}
