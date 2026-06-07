<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ToolQuestion;

/**
 * Checks whether a background process has finished running.
 *
 * Used by RuntimeBashBackgroundPromptAdapter to detect when the bash
 * process completes while waiting for a tool-question answer. When the
 * process finishes, the adapter cancels the pending question and returns
 * false so BashTool's foreground loop can return the completed output.
 *
 * Separating this behind an interface keeps the adapter testable without
 * a real BackgroundProcessManager and respects deptrac boundaries.
 */
interface BackgroundProcessStatusCheckerInterface
{
    /**
     * Returns true when the process identified by PID/sessionId is no
     * longer running (completed, stopped, vanished, or unclean).
     *
     * @param int    $pid       Process PID to check
     * @param string $sessionId Session (run) ID for ownership scoping
     */
    public function isFinished(int $pid, string $sessionId): bool;
}
