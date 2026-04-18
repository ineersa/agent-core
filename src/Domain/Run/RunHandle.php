<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

final readonly class RunHandle
{
    public function __construct(public string $runId)
    {
    }
}
