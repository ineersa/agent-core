<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * POC: defines the input/interaction mode for a tab.
 *
 * This determines what operations are allowed while a tab is active:
 * - Interactive: normal submit/cancel/model controls (parent tab or future
 *   interactive fork child)
 * - ReadOnly: view-only, no submission allowed, cancel/model controls are
 *   no-ops (subagent artifact tabs)
 *
 * When a ReadOnly tab is active, the editor submit does NOT send to
 * the runtime. Instead, a blocking status message tells the user to
 * switch to an interactive tab. Cancel clears the editor only.
 * Model/reasoning controls are no-ops.
 *
 * This is POC/prototype code that will be replaced once the
 * Symfony TUI TabsWidget PR is merged upstream.
 *
 * @see https://github.com/symfony/symfony/pull/64132
 */
enum TabInputModeEnum: string
{
    /** Full submit/cancel/model controls (parent run, future fork child). */
    case Interactive = 'interactive';

    /** View-only artifact tab — no runtime interaction. */
    case ReadOnly = 'readonly';
}
