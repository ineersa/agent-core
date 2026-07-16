<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch;

/**
 * Typed launch intent for a generic deferred child launch task.
 */
interface AgentChildLaunchTaskInterface
{
    public function displayName(): string;

    public function taskSummary(): string;

    /**
     * Optional model hint for durable reservation metadata (null when resolved elsewhere).
     */
    public function definitionModel(): ?string;
}
