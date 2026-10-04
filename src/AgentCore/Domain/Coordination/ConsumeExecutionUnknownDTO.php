<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown;

/** Acknowledges the notice only against its verified owner transition. */
final readonly class ConsumeExecutionUnknownDTO
{
    public function __construct(public ExecutionOutcomeUnknown $notice)
    {
    }
}
