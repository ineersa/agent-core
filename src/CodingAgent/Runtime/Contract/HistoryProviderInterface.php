<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;

/**
 * Provides ordered retained history for a session/run.
 */
interface HistoryProviderInterface
{
    /**
     * @return HistoryView empty when no events
     */
    public function forSession(string $runId): HistoryView;
}
