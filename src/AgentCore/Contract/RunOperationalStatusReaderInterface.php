<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

/**
 * Narrow read seam for execution workers that only need current operational
 * status. It deliberately cannot expose prompt/history state.
 */
interface RunOperationalStatusReaderInterface
{
    public function findOperationalStatus(string $runId): ?RunOperationalStatusDTO;
}
