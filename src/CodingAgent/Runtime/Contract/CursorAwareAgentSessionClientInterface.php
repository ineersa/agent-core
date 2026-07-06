<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Agent session client that can yield canonical runtime events after a seq cursor.
 */
interface CursorAwareAgentSessionClientInterface
{
    /**
     * @return iterable<int, RuntimeEvent>
     */
    public function eventsAfter(string $runId, int $afterSeq): iterable;
}
