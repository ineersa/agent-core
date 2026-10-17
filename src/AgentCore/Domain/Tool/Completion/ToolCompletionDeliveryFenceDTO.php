<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/** Captured at detachment, never refreshed when an outcome arrives. */
final readonly class ToolCompletionDeliveryFenceDTO
{
    public function __construct(
        public int $wakeGeneration,
        public int $historyGeneration,
        public int $controllerGeneration,
    ) {
        if ($wakeGeneration < 0 || $historyGeneration < 0 || $controllerGeneration < 0) {
            throw new \InvalidArgumentException('Completion delivery generations cannot be negative.');
        }
    }

    public static function same(?self $left, ?self $right): bool
    {
        return $left?->wakeGeneration === $right?->wakeGeneration
            && $left?->historyGeneration === $right?->historyGeneration
            && $left?->controllerGeneration === $right?->controllerGeneration;
    }
}
