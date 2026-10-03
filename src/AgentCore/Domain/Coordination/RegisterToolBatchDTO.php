<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;

final readonly class RegisterToolBatchDTO
{
    /** @param list<ExecuteToolCall> $effects */
    public function __construct(public string $runId, public int $turnNo, public string $stepId, public array $effects)
    {
    }
}
