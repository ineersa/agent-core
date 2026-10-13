<?php

declare(strict_types=1);

namespace CastorTasks;

use Symfony\Component\Process\Process;

/**
 * QA-run-owned tmux session inventory and teardown for castor check TUI lanes.
 *
 * Detached TUI E2E sessions are created on the global tmux server, outside the
 * lane setsid session. External lane timeouts can SIGKILL ParaTest workers before
 * PHPUnit tearDown / TmuxHarness::__destruct, so the surviving Castor parent must
 * finalize exact-run tmux resources without broad session sweeps.
 */

/**
 * Tmux session user option storing the exact HATFIELD_QA_RUN_ID for this session.
 */
function qa_tmux_session_ownership_option(): string
{
    return '@hatfield_qa_run_id';
}

function artifact_tmux_session_ownership_option(): string
{
    return '@hatfield_test_artifact_owner';
}

/** @return list<string>|null null means inspection failed, not an empty inventory. */
function inventory_artifact_owner_tmux_sessions(string $ownerId, ?string $qaRunId = null): ?array
{
    // new-session -e installs the token during creation, before the session
    // option is tagged. Inspect both to cover a producer crash in that window.
    // ASCII separators survive tmux's C-locale control-character sanitization.
    // Session IDs, unlike user-selected names, cannot contain that separator.
    $format = '#{session_id}|#{'.artifact_tmux_session_ownership_option().'}|#{HATFIELD_TEST_ARTIFACT_OWNER}|#{'.qa_tmux_session_ownership_option().'}|#{HATFIELD_QA_RUN_ID}';
    $process = new Process(['tmux', 'list-sessions', '-F', $format], env: ['LC_ALL' => 'C'], timeout: 3);
    try {
        $process->run();
    } catch (\Throwable $exception) {
        fwrite(\STDERR, 'test_artifact.tmux_inventory_failed exception='.$exception::class."\n");

        return null;
    }
    if (!$process->isSuccessful()) {
        // tmux has no sessions when the socket does not exist or the server
        // explicitly reports it has stopped. Other failures are uncertain.
        $error = trim($process->getErrorOutput());
        if (1 === $process->getExitCode()
            && (str_starts_with($error, 'no server running on ')
                || (str_starts_with($error, 'error connecting to ') && str_ends_with($error, '(No such file or directory)')))) {
            return [];
        }
        fwrite(\STDERR, 'test_artifact.tmux_inventory_failed exit_code='.$process->getExitCode()."\n");

        return null;
    }
    $sessions = [];
    foreach (explode("\n", rtrim($process->getOutput(), "\r\n")) as $line) {
        if ('' === $line) {
            continue;
        }
        $parts = explode('|', $line);
        if (5 !== \count($parts) || 1 !== preg_match('/^\$\d+$/D', $parts[0])) {
            fwrite(\STDERR, "test_artifact.tmux_inventory_failed malformed_output\n");

            return null;
        }
        if ($parts[1] === $ownerId || $parts[2] === $ownerId
            || (null !== $qaRunId && ($parts[3] === $qaRunId || $parts[4] === $qaRunId))) {
            $sessions[] = $parts[0];
        }
    }
    sort($sessions);

    return array_values(array_unique($sessions));
}

/**
 * Run a bounded tmux CLI invocation. Returns combined stdout/stderr (trimmed).
 */
function run_tmux_command_bounded(string $tmuxArgs, float $timeoutSeconds = 3.0): string
{
    $which = trim((string) shell_exec('which tmux 2>/dev/null'));
    if ('' === $which) {
        return '';
    }

    $cmd = 'tmux '.$tmuxArgs;
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = @proc_open(['sh', '-c', $cmd], $descriptors, $pipes);
    if (!\is_resource($process)) {
        return '';
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $deadline = microtime(true) + max(0.1, $timeoutSeconds);
    $out = '';
    $err = '';
    while (true) {
        $out .= (string) @stream_get_contents($pipes[1]);
        $err .= (string) @stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $out .= (string) @stream_get_contents($pipes[1]);
            $err .= (string) @stream_get_contents($pipes[2]);
            break;
        }
        if (microtime(true) >= $deadline) {
            @proc_terminate($process, 9);
            break;
        }
        usleep(20_000);
    }
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    proc_close($process);

    return trim($out."\n".$err);
}

/**
 * @return list<string>
 */
function inventory_qa_run_owned_tmux_sessions(string $runId): array
{
    if ('' === trim($runId)) {
        return [];
    }

    $format = '#{session_name}'."\t".'#{@hatfield_qa_run_id}';
    $raw = run_tmux_command_bounded('list-sessions -F '.escapeshellarg($format), 5.0);
    if ('' === $raw) {
        return [];
    }

    $sessions = [];
    $splitLines = preg_split('/\r\n|\r|\n/', $raw);
    if (false === $splitLines) {
        $splitLines = [];
    }
    foreach ($splitLines as $line) {
        $line = trim($line);
        if ('' === $line) {
            continue;
        }
        $parts = explode("\t", $line, 2);
        if (2 !== \count($parts)) {
            continue;
        }
        $name = trim($parts[0]);
        $taggedRunId = trim($parts[1]);
        if ('' === $name || $taggedRunId !== $runId) {
            continue;
        }
        $sessions[] = $name;
    }

    sort($sessions);

    return array_values(array_unique($sessions));
}

/**
 * Kill tmux sessions whose @hatfield_qa_run_id matches the given run exactly.
 *
 * @return list<string> session names that were targeted
 */
function teardown_qa_run_owned_tmux_sessions(string $runId): array
{
    $sessions = inventory_qa_run_owned_tmux_sessions($runId);
    foreach ($sessions as $session) {
        run_tmux_command_bounded('kill-session -t '.escapeshellarg($session), 5.0);
    }

    return $sessions;
}

/**
 * @return list<string> session names still owned by this run
 */
function collect_qa_check_run_leaked_tmux_sessions(string $runId): array
{
    return inventory_qa_run_owned_tmux_sessions($runId);
}

/**
 * Standalone castor test:tui: assign a run id when not already under castor check.
 */
function ensure_standalone_tui_qa_run_id(): string
{
    $existing = getenv('HATFIELD_QA_RUN_ID');
    if (false !== $existing && '' !== trim((string) $existing)) {
        TestArtifactLifecycle::current(project_root_dir());
        TestArtifactLifecycle::registerCurrentTmuxOwner();

        return (string) $existing;
    }

    $random = bin2hex(random_bytes(4));
    $id = sanitize_qa_run_id_segment(\sprintf('tui-%s-%d-%s', date('Ymd-His'), getmypid(), $random));
    putenv('HATFIELD_QA_RUN_ID='.$id);
    $_ENV['HATFIELD_QA_RUN_ID'] = $id;
    $_SERVER['HATFIELD_QA_RUN_ID'] = $id;

    // The Castor parent owns run-scoped resources even if its detached worker
    // survives a lane timeout outside the parent's process namespace.
    TestArtifactLifecycle::current(project_root_dir());
    TestArtifactLifecycle::registerCurrentTmuxOwner();

    return $id;
}

/**
 * Finalize QA-run-owned tmux sessions after the TUI lane (success, failure, or timeout).
 */
function finalize_qa_run_tui_tmux_sessions(string $runId): void
{
    if ('' === trim($runId)) {
        return;
    }

    $targeted = teardown_qa_run_owned_tmux_sessions($runId);
    if ([] === $targeted) {
        return;
    }

    $remaining = inventory_qa_run_owned_tmux_sessions($runId);
    if ([] === $remaining) {
        echo 'QA tmux teardown: removed '.\count($targeted)." session(s) for run {$runId}\n";

        return;
    }

    echo 'QA tmux teardown: attempted '.\count($targeted).' session(s); still present: '.implode(', ', $remaining)."\n";
}
