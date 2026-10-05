<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;

final readonly class PreparedToolBatchDTO
{
    /** @param list<ExecuteToolCall> $effects */
    public function __construct(public array $effects, public ?FinalizeToolBatchDTO $action)
    {
    }
}
