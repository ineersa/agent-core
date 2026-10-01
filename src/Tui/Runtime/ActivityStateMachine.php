<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeActivityTransition;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Pure activity state transition for TUI run activity.
 *
 * Thin enum wrapper over {@see RuntimeActivityTransition}, the shared authority
 * also used by cold-resume projections.
 */
final class ActivityStateMachine
{
    /**
     * Compute the next activity state based on the runtime event type.
     *
     * @param RunActivityStateEnum $current Current activity state
     * @param RuntimeEvent         $event   Incoming runtime event
     *
     * @return RunActivityStateEnum Next activity state (unchanged if terminal or unknown event)
     */
    public static function transition(RunActivityStateEnum $current, RuntimeEvent $event): RunActivityStateEnum
    {
        $next = RuntimeActivityTransition::next($current->value, $event);

        return RunActivityStateEnum::tryFrom($next) ?? $current;
    }
}
