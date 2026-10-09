<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Runtime\Contract\RepairResult;

/**
 * Admitted repair mutation. Detection helpers return this instead of committing.
 * The public repair() entry submits it once through RunCommit.
 */
final readonly class SessionRepairPlan
{
    /**
     * @param list<RunEvent> $events
     * @param list<object>   $actions
     * @param list<object>   $effects
     */
    public function __construct(
        public RunState $previousState,
        public RunState $nextState,
        public array $events,
        public array $actions,
        public RepairResult $result,
        public array $effects = [],
    ) {
    }
}
