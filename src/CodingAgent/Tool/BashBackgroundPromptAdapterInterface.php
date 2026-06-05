<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Injectable adapter for bash tool background confirmation prompts.
 *
 * Called by BashTool when a command has been running past the configured
 * background_prompt_threshold_seconds. The adapter decides whether to
 * leave the process running in the background or continue foreground
 * supervision.
 *
 * Production default for TOOLS-09 is non-interactive and declines.
 * TOOLS-09B provides a production runtime/TUI bridge implementation
 * that routes the prompt through the question system.
 *
 * Tests may inject a fake adapter that accepts or declines as needed.
 */
interface BashBackgroundPromptAdapterInterface
{
    /**
     * Ask whether the still-running command should be moved to background.
     *
     * Called once when the threshold is crossed while the process is
     * still running. If the process has finished by the time this is
     * called, the returned value is ignored.
     *
     * @param string $command        The shell command being executed
     * @param int    $pid            Process PID of the running command
     * @param string $logPath        Path to the process log file
     * @param float  $elapsedSeconds Seconds since the process started
     *
     * @return bool true to leave the process running in background,
     *              false to continue waiting/completion
     */
    public function shouldBackground(string $command, int $pid, string $logPath, float $elapsedSeconds): bool;
}
