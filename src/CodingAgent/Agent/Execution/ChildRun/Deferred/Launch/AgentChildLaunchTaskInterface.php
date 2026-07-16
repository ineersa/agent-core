<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch;

/**
 * Typed launch intent shared by deferred child kinds (subagent, fork, …).
 */
interface AgentChildLaunchTaskInterface
{
    public function displayName(): string;

    public function taskSummary(): string;

    /**
     * Optional model hint for durable reservation metadata (null when resolved elsewhere).
     */
    public function definitionModel(): ?string;

    /**
     * Optional reasoning/thinking hint from the parent tool call (persisted on deferred child rows when provided).
     */
    public function reasoningOverride(): ?string;
}
