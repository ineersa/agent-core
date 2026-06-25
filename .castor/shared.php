<?php

declare(strict_types=1);

/**
 * Shared Castor task helpers — functions used broadly across
 * multiple task files.  Keep this file small and single-purpose:
 * each function here is called by at least two distinct task domains.
 *
 * =========================================================================
 * DO NOT add QA orchestration, PHAR logic, testing configuration,
 * process management, or environment-specific code here.  This file
 * exists to prevent circular require_once chains between split
 * task files.
 * =========================================================================
 */

use function CastorTasks\is_llm_mode;
use function CastorTasks\report_path;
use function CastorTasks\summarize_junit_xml;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';

// ─── Quality / assertion helpers ───────────────────────────────────

/**
 * Terminate with a quality failure message.
 *
 * In LLM mode (non-aggregating) the message is written to stderr
 * and the process exits 1.  During parallel aggregation (check/test
 * running multiple steps concurrently) this throws a RuntimeException
 * so the outer runner catches it without killing sibling steps.
 */
function fail_quality(string $message): never
{
    $isAggregating = isset($GLOBALS['CASTOR_CHECK_AGGREGATING']) && true === $GLOBALS['CASTOR_CHECK_AGGREGATING'];
    if (is_llm_mode() && !$isAggregating) {
        fwrite(\STDERR, $message.\PHP_EOL);
        exit(1);
    }

    throw new RuntimeException($message);
}

/**
 * Wrap a shell command with `timeout --kill-after=15s`.
 *
 * The shell-level timeout is the PRIMARY kill mechanism; the
 * Castor-level hard timeout inside run_commands_parallel acts as
 * belt-and-suspenders safety net.
 */
function timeout_check_command(string $command, int $seconds): string
{
    return 'timeout --kill-after=15s '.max(1, $seconds).'s sh -lc '.escapeshellarg($command);
}

// ─── PHPUnit convenience helpers ──────────────────────────────────

/**
 * PHPUnit strict issue flags shared across all test tasks.
 */
function phpunit_strict_issue_flags(): string
{
    return '--stop-on-error --stop-on-failure --fail-on-all-issues --display-all-issues';
}

/**
 * Read the JUnit summary for a previously-run suite.
 */
function read_suite_junit_summary(string $suite): string
{
    $junitPath = report_path('phpunit-'.$suite.'.junit.xml');
    if (!is_file($junitPath)) {
        return '';
    }

    return summarize_junit_xml($junitPath);
}

/**
 * Extract risky-test summary from a PHPUnit log file.
 */
function phpunit_risky_summary(string $logPath): string
{
    if (!is_file($logPath) || !is_readable($logPath)) {
        return '';
    }

    $log = (string) file_get_contents($logPath);
    if ('' === $log) {
        return '';
    }

    if (!preg_match('/There were (\d+) risky tests?:/s', $log, $countMatch)) {
        return '';
    }

    $riskyCount = (int) $countMatch[1];
    if (0 === $riskyCount) {
        return '';
    }

    if (!preg_match('/There were \d+ risky tests?:.*?(?=\n\nOK, |\n\nFAILURES!|\n\nERRORS!|\z)/s', $log, $blockMatch)) {
        return 'risky='.$riskyCount;
    }

    $block = trim($blockMatch[0]);
    $names = [];
    foreach (explode("\n", $block) as $line) {
        if (preg_match('/^\d+\)\s+(.+)/', $line, $nameMat)) {
            $names[] = $nameMat[1];
        }
    }

    $summary = 'risky='.$riskyCount;
    if ([] !== $names) {
        $summary .= ': '.implode(', ', $names);
    }

    return $summary;
}

// ─── Formatting helpers ────────────────────────────────────────────

/**
 * Format step-failure messages for consistent reporting.
 */
function format_step_failures(array $failures): string
{
    $lines = [];
    foreach ($failures as $step => $message) {
        $lines[] = '- '.$step.': '.str_replace("\n", "\n  ", $message);
    }

    return implode("\n", $lines);
}

// ─── Environment / path helpers ────────────────────────────────────

/**
 * Assert tmux is installed.
 *
 * Several Castor tasks (test:tui, test:tui-update, run:agent-test)
 * require tmux for TUI E2E snapshots or the manual tmux test helper.
 * Call this at the top of those tasks to fail early
 * with a clear diagnostic instead of a cryptic proc_open error.
 */
function check_tmux(): void
{
    $which = trim(shell_exec('which tmux 2>/dev/null') ?? '');
    if ('' === $which) {
        throw new RuntimeException('tmux is not installed. Install it with your package manager before using run:* tasks.');
    }
}

/**
 * Shell command run inside tmux for `php bin/console agent` (cd project root, optional env prefix).
 */
function build_agent_console_inner_command(string $envPrefix, string $agentInvocation = 'php bin/console agent'): string
{
    $root = realpath(__DIR__.'/..');
    if (false === $root) {
        throw new RuntimeException('Unable to resolve project root.');
    }

    return sprintf(
        'cd %s && exec %s %s',
        escapeshellarg($root),
        $envPrefix,
        $agentInvocation,
    );
}

/**
 * Run the agent TUI in the current terminal (bash -lc + exec php bin/console agent).
 *
 * Used by run:agent and run:agent-capture so the agent process inherits the caller TTY
 * and stays inside Bubblewrap when Castor re-execed under pi-bwrap.
 */
function launch_agent_direct_terminal(string $innerShellCommand): void
{
    $cmd = sprintf('exec bash -lc %s', escapeshellarg($innerShellCommand));
    passthru($cmd, $exitCode);
    if (0 !== $exitCode) {
        throw new RuntimeException(sprintf('Agent exited with code %d.', $exitCode));
    }
}

/**
 * Launch the agent TUI in tmux (new window when already inside tmux, else new/attach session).
 */
function launch_agent_tmux_session(string $sessionName, string $windowTitle, string $innerShellCommand): void
{
    check_tmux();

    $insideTmux = false !== getenv('TMUX');

    if ($insideTmux) {
        shell_exec(sprintf(
            'tmux new-window -n %s bash -c %s',
            escapeshellarg($windowTitle),
            escapeshellarg($innerShellCommand),
        ));
        echo "Created tmux window '{$windowTitle}'.\n";

        return;
    }

    $cmd = sprintf(
        'tmux new-session -A -s %s bash -lc %s',
        escapeshellarg($sessionName),
        escapeshellarg($innerShellCommand),
    );
    passthru($cmd, $exitCode);
    if (0 !== $exitCode) {
        throw new RuntimeException(sprintf('Agent session exited with code %d.', $exitCode));
    }
}

/**
 * Return a project-root-relative path for absolute paths under the
 * project root; return the input unchanged otherwise.
 */
function project_relative_path(string $absolute): string
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    if (str_starts_with($absolute, $root)) {
        return '.'.substr($absolute, strlen($root));
    }

    return $absolute;
}
