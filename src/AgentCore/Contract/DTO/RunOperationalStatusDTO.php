<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\DTO;

use Ineersa\AgentCore\Domain\Run\RunStatus;

/** Narrow, payload-free state visible outside the application storage layer. */
final readonly class RunOperationalStatusDTO
{
    public function __construct(public RunStatus $status, public int $lastEventSequence)
    {
    }
}
