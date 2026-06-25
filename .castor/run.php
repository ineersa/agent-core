<?php

declare(strict_types=1);

/**
 * Agent runtime launchers: interactive TUI sessions.
 *
 * run:agent and run:agent-capture launch the TUI in the current terminal.
 * run:agent-test is the explicit tmux manual test helper.
 *
 * When ~/bin/pi-bwrap exists (override: HATFIELD_PI_BWRAP), run:agent and
 * run:agent-capture re-exec Castor under Bubblewrap so php bin/console agent
 * runs inside the sandbox unless HATFIELD_BWRAP=0 or HATFIELD_INSIDE_PI_BWRAP=1.
 */

use Castor\Attribute\AsTask;

use function CastorTasks\maybe_reexec_castor_task_under_pi_bwrap;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';

/**
 * Launch the agent TUI in the current terminal.
 *
 * Datadog APM is auto-enabled when ddtrace is loaded and a local trace
 * endpoint is reachable.  Set HATFIELD_DATADOG=0 to force-disable or
 * HATFIELD_DATADOG=1 to force-enable when ddtrace is loaded.
 *
 * No relaunch loop — the TUI runs once and exits naturally.
 */
#[AsTask(name: 'run:agent', description: 'Launch the agent TUI in the current terminal')]
function run_agent(): void
{
    maybe_reexec_castor_task_under_pi_bwrap('run:agent');

    launch_agent_direct_terminal(
        build_agent_console_inner_command(datadog_env_command(datadog_auto_enabled())),
    );
}

/**
 * Launch the agent TUI in a tmux window using the local test model.
 *
 * Datadog APM is always disabled for deterministic test runs.
 */
#[AsTask(name: 'run:agent-test', description: 'Run the agent in a tmux window using the local test model')]
function run_agent_test(): void
{
    // Host tmux server starts the pane command outside Bubblewrap; no auto-wrap here.

    launch_agent_tmux_session(
        sessionName: 'hatfield-agent-test',
        windowTitle: 'hatfield-agent-test',
        innerShellCommand: build_agent_console_inner_command(
            datadog_env_command(false),
            'php bin/console agent --model=llama_cpp_test/test',
        ),
    );
}

/**
 * Launch the agent TUI with raw LLM stream capture enabled.
 *
 * Sets HATFIELD_LLM_RAW_STREAM_CAPTURE=1 so that every raw SSE chunk
 * and its converted deltas are logged to a JSONL file under
 * var/tmp/llm-raw-stream-capture-*.jsonl.
 *
 * The capture file path is printed before the agent launches. The
 * artifact contains raw model output and tool-call arguments \u2014 treat
 * as potentially sensitive and delete/redact before sharing.
 *
 * Uses the configured provider/model (not the test model). Datadog APM
 * is auto-enabled when ddtrace is loaded.
 */
#[AsTask(name: 'run:agent-capture', description: 'Launch the agent TUI with raw LLM stream capture in the current terminal')]
function run_agent_capture(): void
{
    maybe_reexec_castor_task_under_pi_bwrap('run:agent-capture');

    $root = realpath(__DIR__.'/..');
    if (false === $root) {
        throw new RuntimeException('Unable to resolve project root.');
    }

    $ts = date('Ymd-His');
    $capturePath = sprintf('%s/var/tmp/llm-raw-stream-capture-%s-%s.jsonl', $root, $ts, bin2hex(random_bytes(4)));

    echo "[raw-capture] Output: {$capturePath}\n";
    echo "[raw-capture] WARNING: Contains raw model output and tool-call arguments.\n";
    echo "[raw-capture] Treat as sensitive. Delete or redact before sharing.\n";
    echo "\n";

    $inner = sprintf(
        'export HATFIELD_LLM_RAW_STREAM_CAPTURE=1 && export HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH=%s && %s',
        escapeshellarg($capturePath),
        build_agent_console_inner_command(datadog_env_command(datadog_auto_enabled())),
    );

    launch_agent_direct_terminal($inner);
}
