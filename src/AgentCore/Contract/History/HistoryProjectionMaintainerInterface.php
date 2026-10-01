<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Maintains disposable retained-history projections after canonical commits.
 *
 * Implemented by CodingAgent; AgentCore RunCommit depends on this contract only.
 */
interface HistoryProjectionMaintainerInterface
{
    /**
     * @param list<RunEvent> $events already-persisted canonical events
     */
    public function applyCommitted(string $runId, array $events): void;
}
