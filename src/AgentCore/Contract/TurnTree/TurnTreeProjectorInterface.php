<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\TurnTree;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Projects canonical run events into a turn tree snapshot.
 *
 * Implemented by CodingAgent session layer (SESSION-07A); Core rewind/replay
 * depends on this contract only.
 */
interface TurnTreeProjectorInterface
{
    /**
     * @param list<RunEvent> $events Unsorted canonical events
     */
    public function build(string $runId, array $events): TurnTreeSnapshotDTO;
}
