<?php

declare(strict_types=1);

/**
 * Agent runtime launchers: interactive TUI sessions.
 *
 * These tasks launch the agent TUI in tmux for development and
 * manual test inspection.  They do not require the test LLM
 * endpoint by default (run:agent uses the configured provider;
 * run:agent-test forces the local test model).
 */

use Castor\Attribute\AsTask;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';

/**
 * Launch the agent TUI in a tmux session.
 *
 * Inside tmux: creates a new window named "hatfield-agent".
 * Outside tmux: creates or attaches to a session named "hatfield-agent".
 *
 * Datadog APM is auto-enabled when ddtrace is loaded and a local trace
 * endpoint is reachable.  Set HATFIELD_DATADOG=0 to force-disable or
 * HATFIELD_DATADOG=1 to force-enable when ddtrace is loaded.
 *
 * No relaunch loop — the TUI runs once and exits naturally.
 */
#[AsTask(name: 'run:agent', description: 'Launch the agent TUI in a tmux session (hatfield-agent)')]
function run_agent(): void
{
    check_tmux();

    $root = realpath(__DIR__.'/..');
    $session = 'hatfield-agent';
    $insideTmux = false !== getenv('TMUX');

    $innerCmd = sprintf(
        'cd %s && exec %s php bin/console agent',
        escapeshellarg($root),
        datadog_env_command(datadog_auto_enabled()),
    );

    if ($insideTmux) {
        shell_exec(sprintf(
            'tmux new-window -n %s bash -c %s',
            escapeshellarg($session),
            escapeshellarg($innerCmd)
        ));
        echo "Created tmux window '{$session}'.\n";
    } else {
        $cmd = sprintf(
            'tmux new-session -A -s %s bash -lc %s',
            escapeshellarg($session),
            escapeshellarg($innerCmd)
        );
        passthru($cmd, $exitCode);
        if (0 !== $exitCode) {
            throw new RuntimeException(sprintf('Agent session exited with code %d.', $exitCode));
        }
    }
}

/**
 * Launch the agent TUI in a tmux window using the local test model.
 *
 * Datadog APM is always disabled for deterministic test runs.
 */
#[AsTask(name: 'run:agent-test', description: 'Run the agent in a tmux window using the local test model')]
function run_agent_test(): void
{
    check_tmux();

    $root = realpath(__DIR__.'/..');
    $session = 'hatfield-agent-test';
    $insideTmux = false !== getenv('TMUX');

    $innerCmd = sprintf(
        'cd %s && exec %s php bin/console agent --model=llama_cpp_test/test',
        escapeshellarg($root),
        datadog_env_command(false),
    );

    if ($insideTmux) {
        shell_exec(sprintf(
            'tmux new-window -n %s bash -c %s',
            escapeshellarg($session),
            escapeshellarg($innerCmd)
        ));
        echo "Created tmux window '{$session}'.\n";
    } else {
        $cmd = sprintf(
            'tmux new-session -A -s %s bash -lc %s',
            escapeshellarg($session),
            escapeshellarg($innerCmd)
        );
        passthru($cmd, $exitCode);
        if (0 !== $exitCode) {
            throw new RuntimeException(sprintf('Agent test session exited with code %d.', $exitCode));
        }
    }
}
