<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Run\RunState;

/** Writes the payload-free current coordination projection for an active run. */
interface RunOperationalProjectionWriterInterface
{
    public function replace(string $ownerSessionId, RunState $state): void;
}
