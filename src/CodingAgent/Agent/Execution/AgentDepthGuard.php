<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

/**
 * Defense-in-depth guard for v1 subagent launches.
 *
 * Product rule: only a normal parent run or a fork child run may launch a
 * foreground subagent. Subagent children (and other agent_child kinds) must not
 * launch nested subagents. Primary enforcement is excluding the subagent tool
 * from non-fork child toolsets; this guard blocks launch when parent metadata
 * indicates a disallowed child run.
 *
 * Also honors HATFIELD_AGENTS_DISABLED=1 for global disable (subprocess/CLI).
 */
final readonly class AgentDepthGuard
{
    /**
     * Returns null when launch is allowed, or an error message when blocked.
     *
     * @param bool        $parentIsAgentChild true when parent run session.kind is agent_child
     * @param string|null $parentChildKind    session.child_kind for agent_child parents (e.g. fork, subagent)
     */
    public function checkLaunchAllowed(bool $parentIsAgentChild, ?string $parentChildKind = null): ?string
    {
        if ($this->agentsGloballyDisabled()) {
            return 'Agent subagent launches are globally disabled (HATFIELD_AGENTS_DISABLED=1).';
        }

        if (!$parentIsAgentChild) {
            return null;
        }

        if ('fork' === $parentChildKind) {
            return null;
        }

        return 'Nested subagent launches are not supported in v1. Subagents cannot launch subagents.';
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
