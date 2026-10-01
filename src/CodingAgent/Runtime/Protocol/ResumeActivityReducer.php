<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * Compact activity/isCompacting projection for cold resume.
 *
 * Activity transitions are owned by {@see RuntimeActivityTransition}. This
 * reducer only tracks the compacting side-flag needed by SessionInitializer.
 */
final class ResumeActivityReducer
{
    public function __construct(
        private string $activity = 'idle',
        private bool $isCompacting = false,
    ) {
    }

    public function apply(RuntimeEvent $event): void
    {
        if (RuntimeEventTypeEnum::CompactionStarted->value === $event->type) {
            $this->isCompacting = true;
        } elseif (
            RuntimeEventTypeEnum::CompactionCompleted->value === $event->type
            || RuntimeEventTypeEnum::CompactionFailed->value === $event->type
        ) {
            $this->isCompacting = false;
        }

        $this->activity = RuntimeActivityTransition::next($this->activity, $event);
    }

    public function activity(): string
    {
        return $this->activity;
    }

    public function isCompacting(): bool
    {
        return $this->isCompacting;
    }
}
