<?php

declare(strict_types=1);

/**
 * QA orchestration: the `check` command and its direct helpers.
 *
 * QA gate lanes run concurrently (proc_open).  Unit/integration and
 * replay-backed E2E lanes do not require a live LLM.  The `test:llm-real`
 * lane runs the same ParaTest command as `castor test:llm-real` (port 9052 /
 * llama-proxy; warm cache ~22–25s).  Opt-in live controller smoke:
 *   castor test:controller, castor llm:fixtures:record
 *
 * Lanes (typical shell timeouts):
 *   deptrac (30s), test ParaTest (120s), test:controller-replay (90s),
 *   test:tui (120s), test:llm-real (180s), phpstan (90s), cs-check (30s).
 *   No PHAR in the gate.
 *
 * Budget for test:controller-replay (75s → 90s) reflects the current
 * replay E2E suite (8 isolated controller subprocess tests, each
 * spawning controller + messenger consumers with SIGTERM → 3s grace
 * → SIGKILL teardown).  Observed sequential runtime is ~59s when idle;
 * ~71s under active-session host load; 90s gives bounded headroom
 * without masking a true hang.
 *
 * =========================================================================
 * This file was split from the former monolithic .castor/tasks.php.
 * See the sibling files for:
 *   helpers.php  — CastorTasks namespace (PHAR, LLM preflight, reports)
 *   shared.php   — widely-used global helpers (fail_quality, etc.)
 *   process.php  — process management (run_commands_parallel, session
 *                   cleanup, timeout-hardstop smoke proof)
 *   phpunit.php  — PHPUnit tasks (test, ParaTest, sequential builders)
 *   e2e.php      — E2E tasks (test:llm-real, test:tui, test:tui-update,
 *                   test:controller, test:controller-replay)
 *   phar.php     — PHAR packaging tasks
 *   tools.php    — static analysis and code-style tasks
 *   run.php      — agent runtime launchers
 *   cleanup.php  — artifact cleanup
 *   env.php      — diagnostics and Datadog tasks
 *   logs.php     — log management tasks
 * =========================================================================
 */

use Castor\Attribute\AsTask;
use Symfony\Component\Lock\LockInterface;

use function CastorTasks\acquire_castor_check_lock;
use function CastorTasks\assert_castor_check_lane_artifacts_integrity;
use function CastorTasks\assert_castor_check_llama_proxy_cache_unchanged;
use function CastorTasks\assert_castor_check_run_no_process_leaks;
use function CastorTasks\begin_castor_check_llama_proxy_cache_guard;
use function CastorTasks\castor_check_lock_enabled;
use function CastorTasks\check_llm_generation_ready;
use function CastorTasks\finalize_qa_run_tui_tmux_sessions;
use function CastorTasks\initialize_qa_check_run;
use function CastorTasks\is_llm_mode;
use function CastorTasks\release_castor_check_lock;
use function CastorTasks\report_path;
use function CastorTasks\run_quiet_command;
use function CastorTasks\update_castor_check_lock_meta_qa_run_id;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';
require_once __DIR__.'/env.php';
// The following files are loaded by import() ordering in castor.php
// before this file; their global functions are already available.

// ─── Quality gate ─────────────────────────────────────────────────

/**
 * Run full QA gate.
 *
 * Replay-backed controller/TUI E2E and static-analysis lanes run without
 * production LLM providers.  The `test:llm-real` lane hits llama_cpp_test/test
 * on port 9052 (llama-proxy with cache normalization recommended).  Preflight
 * `check_llm_generation_ready()` runs once before parallel lanes start.
 *
 * Concurrent `castor check` invocations for the same git repository (including sibling
 * worktrees) queue on a shared Symfony Lock (FlockStore) outside the worktree. Lanes run concurrently as external subprocesses (via proc_open)
 * so they do not share memory with the Castor PHAR.  Each lane's
 * output is captured to var/reports/check-<step>.log.
 */
#[AsTask(description: 'Run full QA gate (includes live llm-real smoke on port 9052)')]
function check(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    /** @var LockInterface|null $checkLock */
    $checkLock = null;

    try {
        if (castor_check_lock_enabled()) {
            $checkLock = acquire_castor_check_lock($root);
        }

        $qaRunId = initialize_qa_check_run();
        if (null !== $checkLock) {
            update_castor_check_lock_meta_qa_run_id($root, $qaRunId);
        }
        echo 'QA run: '.$qaRunId."\n";

        _run_castor_check_body($root, $qaRunId);
    } finally {
        if (null !== $checkLock) {
            release_castor_check_lock($checkLock, $root);
        }
    }
}

/**
 * Execute the QA gate after optional per-checkout lock and QA run initialization.
 */
function _run_castor_check_body(string $root, string $qaRunId): void
{
    $llamaProxyCacheBaseline = begin_castor_check_llama_proxy_cache_guard();

    // No PHAR ensure — the deterministic controller-replay and TUI
    // replay lanes use source bin/console with APP_ENV=test, which
    // requires autoload-dev paths not bundled in the PHAR.
    $phpBin = \PHP_BINARY;
    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress' : '';

    // Each lane is a shell command that runs the underlying tool
    // directly — not through a Castor task closure — to stay safe
    // inside the Castor PHAR (no pcntl_fork shared-memory issues).
    //
    // Unit/integration ParaTest excludes llm-real (build_check_paratest_command).
    // Live llm-real runs as its own parallel lane (same command as castor test:llm-real).
    $allCheckCommands = [
        'deptrac' => [
            'cmd' => timeout_check_command(
                qa_check_run_env_command().' '.$phpBin.' vendor/bin/deptrac --config-file=depfile.yaml --no-progress --no-ansi'
                    .(is_llm_mode() ? ' --formatter=json' : ''),
                30,
            ),
        ],
        'test' => [
            'cmd' => timeout_check_command(
                // ParaTest unit/integration; PHAR excluded (opt-in, not part of deterministic gate).
                build_check_paratest_command(),
                120,
            ),
        ],
        'test:controller-replay' => [
            'cmd' => timeout_check_command(
                qa_check_run_env_command().' APP_ENV=test '.$phpBin.' vendor/bin/phpunit'
                    .' --group=controller-replay'
                    .' '.$strictFlags.$llmFlags
                    .(is_llm_mode() ? ' --log-junit='.report_path('phpunit-controller-replay.junit.xml') : ''),
                90,
            ),
        ],
        'test:tui' => [
            'cmd' => timeout_check_command(
                build_test_tui_phpunit_command(null),
                120,
            ),
        ],
        'test:llm-real' => [
            'cmd' => timeout_check_command(
                build_test_llm_real_phpunit_command(null),
                180,
            ),
        ],
        'phpstan' => [
            'cmd' => timeout_check_command(
                qa_check_run_env_command().' '.$phpBin.' vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress'
                    .(is_llm_mode() ? ' --error-format=json --no-ansi' : ''),
                90,
            ),
        ],
        'cs-check' => [
            'cmd' => timeout_check_command(
                qa_check_run_env_command().' '.$phpBin.' vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --no-ansi'
                    .(is_llm_mode() ? ' --format=json --show-progress=none' : ' --diff'),
                30,
            ),
        ],
    ];

    // DB schema must be ready before the test / controller-replay / TUI
    // lanes start.  Migrate once (fast, idempotent).
    $migrate = run_quiet_command(
        qa_check_run_env_command().' APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate->getExitCode()) {
        fail_quality('test database migration failed: '.$migrate->getErrorOutput());
    }

    // Tmux is required for the deterministic TUI E2E lane.
    // Fail early with a clear diagnostic instead of letting the
    // TUI lane time out or skip silently.
    $which = trim(shell_exec('which tmux 2>/dev/null') ?? '');
    if ('' === $which) {
        fail_quality('tmux is not installed. The QA gate requires tmux for the TUI E2E lane. Install it with your package manager.');
    }

    // Fail fast before spawning parallel lanes if port 9052 cannot complete generation.
    check_llm_generation_ready();

    $failures = [];
    $timings = [];

    $GLOBALS['CASTOR_CHECK_AGGREGATING'] = true;
    try {
        $useParallel = \PHP_SAPI === 'cli' && function_exists('proc_open');
        if ($useParallel) {
            run_check_commands_parallel($allCheckCommands, $failures, $timings);
        } else {
            run_check_commands_sequential($allCheckCommands, $failures, $timings);
        }
    } finally {
        unset($GLOBALS['CASTOR_CHECK_AGGREGATING']);
        unset($GLOBALS['CASTOR_PHAR_READY']);
    }

    assert_castor_check_llama_proxy_cache_unchanged($llamaProxyCacheBaseline);

    finalize_qa_run_tui_tmux_sessions($qaRunId);

    finalize_castor_check_run($qaRunId, $failures, $timings, array_keys($allCheckCommands));
}

/**
 * Post-lane assertions shared by success and failure paths (no auto-kill).
 *
 * @param array<string, string>    $failures
 * @param array<string, float|int> $timings
 * @param list<string>             $laneSteps
 */
function finalize_castor_check_run(string $qaRunId, array $failures, array $timings, array $laneSteps): void
{
    assert_castor_check_lane_artifacts_integrity($laneSteps);
    assert_castor_check_run_no_process_leaks($qaRunId);

    if ([] !== $failures) {
        fail_quality('quality failed:'.\PHP_EOL.format_step_failures($failures));
    }

    echo sprintf('

quality: ok (%.1fs)
', array_sum($timings));
}

// ─── Stale worker cleanup ────────────────────────────────────────

/**
 * Kill stale workers from previous castor check runs in this checkout.
 *
 * Matches leaked PHAR or source `bin/console` messenger:consume /
 * agent --controller children, stale vendor/bin/phpunit processes, and
 * orphaned castor check runs rooted in the current checkout.
 *
 * Safety boundaries (Linux `/proc` fail-safe):
 * - Only PIDs owned by `posix_geteuid()` are considered; root-owned or
 *   other-user workers are never signaled (Castor is expected to run as the
 *   normal non-root developer user, not as root).
 * - Checkout membership uses argv containing `$root` OR `/proc/<pid>/cwd`
 *   under `$root` (readlink may fail for dead/zombie PIDs — then the PID is
 *   skipped rather than killed).
 * - Does not touch sibling worktrees, the llama.cpp server, or any PID on
 *   the current Castor process ancestry (timeout/shell/Symfony Process/Hatfield
 *   launchers included).
 * - Skips messenger:consume / agent --controller PIDs whose /proc environ
 *   contains HATFIELD_SESSION_ID= (live Hatfield session control-plane workers).
 *   Silent when no stale workers exist.
 */
function _stale_check_worker_owned_by_current_user(int $pid): bool
{
    if ($pid <= 1) {
        return false;
    }
    $stat = @stat("/proc/{$pid}");
    if (false === $stat) {
        return false;
    }

    return $stat['uid'] === posix_geteuid();
}

/**
 * True when the process cwd is under $root.  Used when argv does not embed
 * the checkout path (e.g. replay E2E cwd = isolated `var/tmp/test-*`, or
 * relative `vendor/bin/phpunit` / `castor check` launched from a subdir).
 */
function _stale_check_worker_cwd_under_root(int $pid, string $root): bool
{
    $cwdLink = "/proc/{$pid}/cwd";
    $cwd = @readlink($cwdLink);
    if (false === $cwd || '' === $cwd) {
        return false;
    }
    $rootReal = realpath($root);
    $cwdReal = realpath($cwd);
    if (false === $rootReal || false === $cwdReal) {
        return false;
    }
    $prefix = rtrim($rootReal, '/').'/';

    return $cwdReal === $rootReal || str_starts_with($cwdReal, $prefix);
}

/**
 * Parent PID from Linux /proc, or null when unavailable.
 */
function _stale_check_worker_parent_pid(int $pid): ?int
{
    if ($pid <= 1) {
        return null;
    }

    $status = @file_get_contents("/proc/{$pid}/status");
    if (false === $status) {
        return null;
    }

    if (!preg_match('/^PPid:\s+(\d+)/m', $status, $m)) {
        return null;
    }

    $ppid = (int) $m[1];
    if ($ppid <= 0 || $ppid === $pid) {
        return null;
    }

    return $ppid;
}

/**
 * PIDs on the chain from the current Castor PHP process up through parents.
 *
 * Castor is often launched under timeout, a shell, Symfony Process, or Hatfield
 * tool workers. Stale cleanup matches `castor check` by cwd-under-checkout plus
 * cmdline substring; without this guard, an ancestor timeout wrapper can be
 * killed and abort the active gate before lanes run.
 *
 * @return array<int, true> pid => true
 */
function _stale_check_worker_protected_launcher_ancestry(): array
{
    $protected = [];
    $pid = getmypid();
    $seen = [];

    while ($pid > 1) {
        if (isset($seen[$pid])) {
            break;
        }
        $seen[$pid] = true;
        $protected[$pid] = true;

        $ppid = _stale_check_worker_parent_pid($pid);
        if (null === $ppid) {
            break;
        }

        $pid = $ppid;
    }

    return $protected;
}

function _stale_check_worker_belongs_to_checkout(int $pid, string $cmdline, string $root): bool
{
    $rootPat = preg_quote($root, '/');
    if (preg_match("/{$rootPat}/", $cmdline)) {
        return true;
    }

    return _stale_check_worker_cwd_under_root($pid, $root);
}

/**
 * True when the process environment marks an active Hatfield session consumer.
 *
 * HeadlessController passes HATFIELD_SESSION_ID to controller and messenger
 * children; stale cleanup must not kill those siblings when castor check runs
 * from inside the same checkout/session (see issue #208).
 */
function _stale_check_worker_has_hatfield_session_env(int $pid): bool
{
    if ($pid <= 1) {
        return false;
    }

    $pidEnv = @file_get_contents("/proc/{$pid}/environ");
    if (false === $pidEnv || '' === $pidEnv) {
        return false;
    }

    return str_contains($pidEnv, 'HATFIELD_SESSION_ID=');
}

/**
 * @return list<array{pid:int, cmdline:string, reason:string}>
 */
function collect_stale_check_worker_candidates(string $root): array
{
    $output = [];
    @exec('ps -eo pid=,args= 2>/dev/null', $output);
    if ([] === $output) {
        return [];
    }

    $protectedLauncherPids = _stale_check_worker_protected_launcher_ancestry();
    $pharGlob = $root.'/var/tmp/phar/hatfield.phar';
    $pharPat = preg_quote($pharGlob, '/');
    $consolePat = preg_quote($root.'/bin/console', '/');

    $candidates = [];
    foreach ($output as $raw) {
        $line = trim($raw);
        if ('' === $line) {
            continue;
        }
        if (!preg_match('/^\s*(\d+)\s+(.+)$/s', $line, $m)) {
            continue;
        }
        $pid = (int) $m[1];
        if ($pid <= 1 || isset($protectedLauncherPids[$pid])) {
            continue;
        }
        if (!_stale_check_worker_owned_by_current_user($pid)) {
            continue;
        }
        $cmdline = $m[2];

        if (!_stale_check_worker_belongs_to_checkout($pid, $cmdline, $root)) {
            continue;
        }

        if (preg_match("/{$pharPat}/", $cmdline)
            && (str_contains($cmdline, 'messenger:consume')
                || str_contains($cmdline, 'agent --controller'))) {
            if (_stale_check_worker_has_hatfield_session_env($pid)) {
                continue;
            }
            $candidates[] = ['pid' => $pid, 'cmdline' => $cmdline, 'reason' => 'leaked phar messenger/controller'];
            continue;
        }

        if (preg_match("/{$consolePat}/", $cmdline)
            && (str_contains($cmdline, 'messenger:consume')
                || str_contains($cmdline, 'agent --controller'))) {
            if (_stale_check_worker_has_hatfield_session_env($pid)) {
                continue;
            }
            $candidates[] = ['pid' => $pid, 'cmdline' => $cmdline, 'reason' => 'leaked source messenger/controller'];
            continue;
        }

        if (str_contains($cmdline, 'vendor/bin/phpunit')) {
            $candidates[] = ['pid' => $pid, 'cmdline' => $cmdline, 'reason' => 'stale phpunit'];
            continue;
        }

        if (str_contains($cmdline, 'castor check')) {
            $candidates[] = ['pid' => $pid, 'cmdline' => $cmdline, 'reason' => 'stale castor check'];
        }
    }

    return $candidates;
}

/**
 * Last-resort debug cleanup for leaked QA workers in this checkout.
 *
 * Not invoked by `castor check`. Prefer fixing lifecycle leaks at the source.
 */
function cleanup_stale_check_workers(string $root): void
{
    $candidates = collect_stale_check_worker_candidates($root);
    if ([] === $candidates) {
        return;
    }

    $pidsToKill = array_map(static fn (array $row): int => $row['pid'], $candidates);
    $pidList = implode(' ', $pidsToKill);
    echo "Killing stale check worker PIDs: {$pidList}\n";

    foreach ($pidsToKill as $pid) {
        @posix_kill($pid, \SIGTERM);
    }
    usleep(1_000_000);
    foreach ($pidsToKill as $pid) {
        if (@posix_kill($pid, 0)) {
            @posix_kill($pid, \SIGKILL);
        }
    }
}

#[AsTask(name: 'cleanup:workers:list', namespace: 'clean', description: 'List stale QA worker candidates in this checkout (dry-run)')]
function cleanup_workers_list(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $candidates = collect_stale_check_worker_candidates($root);
    if ([] === $candidates) {
        echo "No stale QA worker candidates in {$root}\n";
        exit(0);
    }

    echo "Stale QA worker candidates in {$root}:\n";
    foreach ($candidates as $row) {
        echo sprintf("  pid=%d reason=%s\n    %s\n", $row['pid'], $row['reason'], $row['cmdline']);
    }
    echo "\nUse castor clean:cleanup:workers only as explicit last resort after investigating leaks.\n";
    exit(0);
}

#[AsTask(name: 'cleanup:workers', namespace: 'clean', description: 'Kill stale QA workers in this checkout (last-resort debug only)')]
function cleanup_workers(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $before = collect_stale_check_worker_candidates($root);
    if ([] === $before) {
        echo "No stale QA worker candidates in {$root}\n";
        exit(0);
    }

    echo "Last-resort cleanup — investigate lifecycle leaks instead of relying on this.\n";
    cleanup_stale_check_workers($root);
    $after = collect_stale_check_worker_candidates($root);
    if ([] === $after) {
        echo "Cleanup complete.\n";
        exit(0);
    }

    echo "Some candidates may still be alive (zombies or respawned). Re-run castor clean:cleanup:workers:list.\n";
    exit(1);
}

// ─── Check command runners ───────────────────────────────────────

/**
 * Run check steps in parallel via proc_open subprocesses.
 *
 * Each step's stdout+stderr is captured to
 * var/reports/check-<step>.log.  The parent waits for all
 * processes, then prints a combined timing summary.
 *
 * @param array<string,array{cmd:string}> $steps
 * @param array<string,string>            $failures out
 * @param array<string,float|int>         $timings  out
 */
function run_check_commands_parallel(array $steps, array &$failures, array &$timings): void
{
    $logFiles = [];
    $commands = [];
    $timeouts = [];
    foreach ($steps as $step => $info) {
        $logFiles[$step] = report_path("check-{$step}.log");
        $commands[$step] = [
            'cmd' => $info['cmd'],
            'log' => $logFiles[$step],
        ];
        // Extract the shell-timeout seconds from the step command so
        // Castor can enforce a belt-and-suspenders hard timeout in the
        // poll loop.  Shell timeouts may not kill the full descendant
        // tree; the Castor-level timeout guarantees we never hang on a
        // blocked pipe.
        if (preg_match('/^timeout\s+.*?\s+(\d+)s\s/', $info['cmd'], $m)) {
            // Pad 15 s to avoid racing the shell timeout.  The Castor
            // hard timeout is the safety net; the shell timeout is the
            // primary kill.
            $timeouts[$step] = (int) $m[1] + 15;
        }
    }
    @mkdir(\CastorTasks\reports_dir(), 0755, true);

    echo 'Running steps in parallel (proc_open):
';
    foreach (array_keys($steps) as $step) {
        echo "  - {$step}\n";
    }
    echo '
';

    $results = run_commands_parallel($commands, $timeouts);

    foreach ($steps as $step => $_) {
        $result = $results[$step] ?? ['exitCode' => -1, 'output' => 'proc_open failed', 'duration' => 0];
        $timings[$step] = $result['duration'];
        if (0 !== $result['exitCode']) {
            $failures[$step] = $result['exitCode'] < 0
                ? $result['output']
                : 'exit code '.$result['exitCode'];
        }
    }

    echo 'Summary:
';
    foreach ($steps as $step => $_) {
        $status = isset($failures[$step]) ? 'FAIL' : 'OK';
        $duration = $timings[$step] ?? 0;
        echo sprintf('  %-20s %s  (%.1fs)', $step, $status, $duration);
        if (isset($failures[$step])) {
            echo "  — {$failures[$step]}";
        } elseif (is_file($logFiles[$step])) {
            $logContent = file_get_contents($logFiles[$step]);
            if (false !== $logContent) {
                $lines = explode("\n", trim($logContent));
                $lastLine = end($lines);
                if ('' !== $lastLine) {
                    echo "  — {$lastLine}";
                }
            }
        }
        echo '
';
    }
}

/**
 * Fallback sequential runner (proc_open not available).
 *
 * Runs every step in order with per-step timing.
 *
 * @param array<string,array{cmd:string}> $steps
 * @param array<string,string>            $failures out
 * @param array<string,float>             $timings  out
 */
function run_check_commands_sequential(array $steps, array &$failures, array &$timings): void
{
    echo 'Running steps sequentially (proc_open not available):

';

    $overallStart = hrtime(true);

    foreach ($steps as $step => $info) {
        echo sprintf('  %-20s ... ', $step);
        $start = hrtime(true);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(
            ['setsid', '-w', 'sh', '-lc', $info['cmd']],
            $descriptors,
            $pipes,
        );

        if (!is_resource($process)) {
            $duration = (hrtime(true) - $start) / 1e9;
            $timings[$step] = $duration;
            $failures[$step] = 'proc_open failed';
            echo sprintf('FAIL (%.1fs): proc_open failed
', $duration);
            continue;
        }

        $sid = proc_get_status($process)['pid'];

        // Non-blocking incremental read to prevent hanging when
        // grandchildren survive and hold pipe write ends open.
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $outBuf = '';
        $errBuf = '';
        $deadline = (hrtime(true) / 1e9) + 75.0; // generous fallback

        while (true) {
            $status = proc_get_status($process);
            $chunk = @fread($pipes[1], 65536);
            if (false !== $chunk && '' !== $chunk) {
                $outBuf .= $chunk;
            }
            $chunk = @fread($pipes[2], 65536);
            if (false !== $chunk && '' !== $chunk) {
                $errBuf .= $chunk;
            }
            if (!$status['running']) {
                break;
            }
            if ((hrtime(true) / 1e9) >= $deadline) {
                _reap_session($sid > 0 ? $sid : null);
                _reap_process_group($sid > 0 ? $sid : null);
                $outBuf .= "\n[Castor sequential hard timeout after 75s]";
                break;
            }
            usleep(50000);
        }

        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Reap entire session (primary) + process group (belt-and-suspenders).
        _reap_session($sid > 0 ? $sid : null);
        _reap_process_group($sid > 0 ? $sid : null);

        $duration = (hrtime(true) - $start) / 1e9;
        $timings[$step] = $duration;

        if (0 !== $exitCode) {
            $failures[$step] = 'exit code '.$exitCode;
            echo sprintf('FAIL (%.1fs): exit code %d
', $duration, $exitCode);
        } else {
            echo sprintf('ok (%.1fs)
', $duration);
        }
    }

    echo sprintf('
Total: %.1fs
', (hrtime(true) - $overallStart) / 1e9);
}
