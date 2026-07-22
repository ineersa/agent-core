<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Rewind;

use Ineersa\AgentCore\Domain\Run\RunState;

interface RunRewindServiceInterface
{
    /** @return array{rebuiltState: RunState, leafSetSeq: int} */
    public function rewind(string $runId, int $targetTurnNo): array;
}
