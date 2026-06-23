<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

/**
 * Defense-in-depth guard for v1 subagent launches.
 *
 * Product rule: only a normal (non-child) parent run may launch a foreground
 * subagent. Child runs (session kind agent_child) must not launch nested
 * subagents. Primary enforcement is excluding the subagent tool from child
 * toolsets; this guard blocks launch when parent metadata indicates a child run.
 *
 * Also honors HATFIELD_AGENTS_DISABLED=1 for global disable (subprocess/CLI).
 */
final readonly class AgentDepthGuard
{
    /**
     * Returns null when launch is allowed, or an error message when blocked.
     */
    public function checkLaunchAllowed(bool $parentIsAgentChild): ?string
    {
        if ($this->agentsGloballyDisabled()) {
            return 'Agent subagent launches are globally disabled (HATFIELD_AGENTS_DISABLED=1).';
        }

        if ($parentIsAgentChild) {
            return 'Nested subagent launches are not supported in v1. Subagents cannot launch subagents.';
        }

        return null;
    }

    public function agentsGloballyDisabled(): bool
    {
        $disabled = getenv('HATFIELD_AGENTS_DISABLED');

        if (false === $disabled || '' === $disabled) {
            return false;
        }

        return '1' === $disabled || 'true' === $disabled;
    }
}
