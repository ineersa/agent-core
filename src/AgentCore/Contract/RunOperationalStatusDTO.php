<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunStatus;

/** Narrow, payload-free state visible outside the application storage layer. */
final readonly class RunOperationalStatusDTO
{
    public function __construct(
        public string $runId,
        public RunStatus $status,
        public ?CurrentOperationDTO $currentOperation,
    ) {
    }
}
