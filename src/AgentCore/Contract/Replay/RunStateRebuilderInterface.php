<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Replay;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Domain\Run\RunState;

interface RunStateRebuilderInterface
{
    public function rebuildIfStale(RunState $state, string $runId): RunStateReplayResult;

    public function rebuildForLeaf(RunState $state, string $runId, int $targetLeafTurnNo): RunStateReplayResult;
}
