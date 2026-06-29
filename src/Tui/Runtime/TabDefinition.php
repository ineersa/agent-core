<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * POC: definition of a single tab in the TUI.
 *
 * Each tab has an identity (id, label), the run ID it tracks,
 * and the mutable TuiSessionState that drives its transcript/activity.
 *
 * This is POC/prototype code and will be replaced once the
 * Symfony TUI TabsWidget PR is merged upstream.
 *
 * @see https://github.com/symfony/symfony/pull/64132
 */
final readonly class TabDefinition
{
    /**
     * @param string          $id    Unique tab identifier (e.g. 'parent', 'fork-<runId>')
     * @param string          $label Human-readable tab label shown in the tab bar
     * @param string          $runId The run ID this tab is tracking
     * @param TuiSessionState $state Mutable state bag for transcript/activity/polling
     * @param bool            $isRun Whether this tab represents an active/runnable session (vs. static info)
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $runId,
        public TuiSessionState $state,
        public bool $isRun = true,
    ) {
    }
}
