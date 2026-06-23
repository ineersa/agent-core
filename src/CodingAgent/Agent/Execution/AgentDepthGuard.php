<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

/**
 * Enforces recursion/depth limits for agent subagent launches.
 *
 * Guards at two levels:
 *  1. Environment variables (protect subprocess/CLI boundaries):
 *     HATFIELD_AGENTS_DISABLED=1 → all agents blocked
 *     HATFIELD_AGENT_DEPTH=N → current depth
 *     HATFIELD_AGENT_MAX_DEPTH=N → per-parent maximum
 *
 *  2. Persisted metadata (protect in-process execution and replay/resume):
 *     Child RunStarted metadata carries agent_depth, agent_max_depth,
 *     and agents_disabled fields.
 *
 * For in-process child runs, metadata is authoritative because env vars
 * are not propagated.  Use {@see determineDepth()} which combines both
 * sources, taking the maximum of env and metadata depth so neither
 * source can be used to bypass the other.
 *
 * The default parent depth is 0.  A definition with maxDepth=1 allows
 * parent→child but blocks child→grandchild.
 *
 * Only the non-interactive v1 path is implemented.  If a subagent tool
 * call is blocked by depth limits, the caller should throw a
 * non-retryable ToolCallException.
 */
final readonly class AgentDepthGuard
{
    /**
     * Derive the current agent depth from environment variables only.
     *
     * Returns 0 for the parent (no HATFIELD_AGENT_CHILD set),
     * otherwise HATFIELD_AGENT_DEPTH as an integer (default 1).
     *
     * Prefer {@see determineDepth()} for the combined env+metadata depth.
     */
    public function currentDepth(): int
    {
        $child = getenv('HATFIELD_AGENT_CHILD');
        if (false === $child || '' === $child || '0' === $child) {
            return 0;
        }

        $depth = getenv('HATFIELD_AGENT_DEPTH');
        if (false === $depth || '' === $depth) {
            return 1;
        }

        return max(0, (int) $depth);
    }

    /**
     * Determine the effective current depth by combining environment
     * variables and persisted RunStarted metadata.
     *
     * Uses max(envDepth, metadataDepth) so that neither source can be
     * used to bypass the other.  When metadata is null (parent is not a
     * child, or the parent's RunStarted event is not yet available),
     * falls back to the env-derived depth.
     *
     * @param int|null $metadataDepth depth read from parent's RunStarted
     *                                metadata (or null when N/A)
     */
    public function determineDepth(?int $metadataDepth = null): int
    {
        $envDepth = $this->currentDepth();
        $effective = \is_int($metadataDepth) ? max($envDepth, $metadataDepth) : $envDepth;

        return $effective;
    }

    /**
     * Check whether a child launch is allowed given the parent environment
     * and the target agent definition's maxDepth.
     *
     * Returns null on success, or an error message string on failure.
     */
    public function checkAllowed(
        int $currentDepth,
        int $agentMaxDepth,
    ): ?string {
        // Globally disabled by env var.
        if ($this->agentsGloballyDisabled()) {
            return 'Agent subagent launches are globally disabled (HATFIELD_AGENTS_DISABLED=1).';
        }

        // Respect the global max depth from env if set.
        $globalMaxDepth = $this->globalMaxDepth();
        if (null !== $globalMaxDepth) {
            $agentMaxDepth = min($agentMaxDepth, $globalMaxDepth);
        }

        if ($currentDepth >= $agentMaxDepth) {
            return \sprintf(
                'Agent recursion blocked: current depth %d meets or exceeds max depth %d.',
                $currentDepth,
                $agentMaxDepth,
            );
        }

        return null;
    }

    /**
     * Compute the child depth for a proposed subagent launch.
     */
    public function childDepth(int $currentDepth): int
    {
        return $currentDepth + 1;
    }

    /**
     * Compute child env vars to propagate to a subprocess.
     *
     * @return array<string, string>
     */
    public function childEnv(int $childDepth, int $maxDepth, bool $agentsDisabled): array
    {
        $env = [
            'HATFIELD_AGENT_CHILD' => '1',
            'HATFIELD_AGENT_DEPTH' => (string) $childDepth,
            'HATFIELD_AGENT_MAX_DEPTH' => (string) $maxDepth,
        ];

        if ($agentsDisabled) {
            $env['HATFIELD_AGENTS_DISABLED'] = '1';
        }

        return $env;
    }

    /**
     * Are all agent launches globally disabled via env var?
     */
    public function agentsGloballyDisabled(): bool
    {
        $disabled = getenv('HATFIELD_AGENTS_DISABLED');

        if (false === $disabled || '' === $disabled) {
            return false;
        }

        return '1' === $disabled || 'true' === $disabled;
    }

    /**
     * Global max depth override from env var (null = not set).
     */
    private function globalMaxDepth(): ?int
    {
        $maxDepth = getenv('HATFIELD_AGENT_MAX_DEPTH');
        if (false === $maxDepth || '' === $maxDepth) {
            return null;
        }

        $parsed = (int) $maxDepth;

        return $parsed > 0 ? $parsed : null;
    }
}
