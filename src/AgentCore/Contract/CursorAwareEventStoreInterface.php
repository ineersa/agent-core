<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Event store that can stream canonical events after a sequence cursor.
 *
 * Used by the headless controller drain loop to avoid re-reading and
 * denormalizing full run history on every poll tick.
 */
interface CursorAwareEventStoreInterface extends EventStoreInterface
{
    /**
     * @return iterable<int, RunEvent> Events with seq strictly greater than $afterSeq
     */
    public function allForAfter(string $runId, int $afterSeq): iterable;
}
