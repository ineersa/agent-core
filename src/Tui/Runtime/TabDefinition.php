<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * POC: definition of a single tab in the TUI.
 *
 * Each tab has an identity (id, label), the run ID it tracks,
 * the mutable TuiSessionState that drives its transcript/activity,
 * and an input mode that determines allowed interactions.
 *
 * Note: NOT readonly — label is mutable so the tab bar can reflect
 * status changes (running → completed) without recreating the tab.
 *
 * This is POC/prototype code and will be replaced once the
 * Symfony TUI TabsWidget PR is merged upstream.
 *
 * @see https://github.com/symfony/symfony/pull/64132
 */
final class TabDefinition
{
    /**
     * @param string           $id        Unique tab identifier (e.g. 'parent', 'fork-<runId>')
     * @param string           $label     Human-readable tab label shown in the tab bar
     * @param string           $runId     The run ID this tab is tracking
     * @param TuiSessionState  $state     Mutable state bag for transcript/activity/polling
     * @param TabInputModeEnum $inputMode Interaction mode (Interactive vs ReadOnly)
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $runId,
        public TuiSessionState $state,
        public TabInputModeEnum $inputMode = TabInputModeEnum::Interactive,
    ) {
    }
}
