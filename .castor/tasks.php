<?php

declare(strict_types=1);

/**
 * QA orchestration: the `check` command and its direct helpers.
 *
 * This is the central quality gate.  It runs PHAR ensure, then
 * executes all validation steps concurrently (deptrac, unit-test
 * shards, controller E2E, real LLM E2E, TUI E2E, phpstan, cs-check).
 *
 * =========================================================================
 * This file was split from the former monolithic .castor/tasks.php.
 * See the sibling files for:
 *   helpers.php  — CastorTasks namespace (PHAR, LLM preflight, reports)
 *   shared.php   — widely-used global helpers (fail_quality, etc.)
 *   process.php  — process management (run_commands_parallel, session
 *                   cleanup, timeout-hardstop smoke proof)
 *   phpunit.php  — PHPUnit tasks and shard discovery (test, shard
 *                   groups, worker command builders)
 *   e2e.php      — E2E tasks (test:llm-real, test:tui, test:tui-update,
 *                   test:controller)
 *   phar.php     — PHAR packaging tasks
 *   tools.php    — static analysis and code-style tasks
 *   run.php      — agent runtime launchers
 *   cleanup.php  — artifact cleanup
 *   env.php      — diagnostics and Datadog tasks
 *   logs.php     — log management tasks
 *
 * FUTURE (MAINT-05G): The `check` command will be refactored to use
 *   the deterministic command matrix (replay E2E, ParaTest unit
 *   acceleration, journey-based TUI).  The current live-LLM steps
 *   will become opt-in only.
 * =========================================================================
 */

use Castor\Attribute\AsTask;

use function CastorTasks\check_llm_generation_ready;
use function CastorTasks\is_llm_mode;
use function CastorTasks\phar_ensure;
use function CastorTasks\report_path;
use function CastorTasks\run_quiet_command;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';
// The following files are loaded by import() ordering in castor.php
// before this file; their global functions are already available.

// ─── Quality gate ─────────────────────────────────────────────────

/**
 * Run full QA: deptrac, phpunit, controller E2E, real LLM E2E,
 * TUI snapshot E2E, phpstan, cs-fixer check.
 *
 * All steps run concurrently as external subprocesses (via proc_open)
 * so they do not share memory with the Castor PHAR.  Each step's
 * output is captured to var/reports/check-<step>.log.
 */
#[AsTask(description: 'Run full QA (deptrac, phpunit, controller E2E, real LLM E2E, TUI E2E, phpstan, cs-fixer)')]
function check(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    // Belt-and-suspenders: kill stale messenger/controller workers from
    // previous castor check runs that may have leaked through per-step
    // timeouts.  Scoped to this checkout only.
    cleanup_stale_check_workers($root);

    $pharStart = hrtime(true);
    $pharPath = '';
    try {
        $pharPath = phar_ensure();
    } catch (Throwable $e) {
        echo "PHAR ensure skipped: {$e->getMessage()}
";
    }
    if ('' !== $pharPath) {
        $GLOBALS['CASTOR_PHAR_READY'] = $pharPath;
    }
    $pharDuration = (hrtime(true) - $pharStart) / 1e9;
    echo sprintf('PHAR: ok (%.1fs)

', $pharDuration);

    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';
    $phpBin = \PHP_BINARY;
    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress' : '';

    // Each step is a shell command that runs the underlying tool
    // directly — not through a Castor task closure — to stay safe
    // inside the Castor PHAR (no pcntl_fork shared-memory issues).
    //
    // MAINT-05B: The PHPUnit lane uses a single sequential run
    // (build_sequential_phpunit_command) instead of 7 custom shard
    // workers.  This simplifies the parallel topology, removes
    // stale-worker risk from per-shard timeouts, and keeps the check
    // output readable.  Use `castor test` for ParaTest-powered unit
    // acceleration (the default path).
    $allCheckCommands = [
        'deptrac' => [
            'cmd' => timeout_check_command(
                $phpBin.' vendor/bin/deptrac --config-file=depfile.yaml --no-progress --no-ansi'
                    .(is_llm_mode() ? ' --formatter=json' : ''),
                30,
            ),
        ],
        'test' => [
            'cmd' => timeout_check_command(
                build_sequential_phpunit_command($pharEnv),
                300,
            ),
        ],
        'test:controller' => [
            'cmd' => timeout_check_command(
                'APP_ENV=test '.$pharEnv.'LLAMA_CPP_SMOKE_TEST=1 '.$phpBin.' vendor/bin/phpunit'
                    .' --filter=ControllerSmokeTest'
                    .' '.$strictFlags.$llmFlags
                    .(is_llm_mode() ? ' --log-junit='.report_path('phpunit-controller.junit.xml') : ''),
                30,
            ),
        ],
        'test:llm-real' => [
            'cmd' => timeout_check_command(
                'APP_ENV=test '.$pharEnv.'LLAMA_CPP_SMOKE_TEST=1 '.$phpBin.' vendor/bin/phpunit'
                    .' --group llm-real'
                    .' '.$strictFlags.$llmFlags
                    .(is_llm_mode() ? ' --log-junit='.report_path('phpunit-llm-real.junit.xml') : ''),
                60,
            ),
        ],
        'test:tui' => [
            'cmd' => timeout_check_command(
                'APP_ENV=test '.$phpBin.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
                .' && APP_ENV=test '.$phpBin.' vendor/bin/phpunit'
                .' --group tui-e2e-replay'
                .' '.$strictFlags.$llmFlags
                .(is_llm_mode() ? ' --log-junit='.report_path('phpunit-tui.junit.xml') : ''),
                120,
            ),
        ],
        'phpstan' => [
            'cmd' => timeout_check_command(
                $phpBin.' vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress'
                    .(is_llm_mode() ? ' --error-format=json --no-ansi' : ''),
                30,
            ),
        ],
        'cs-check' => [
            'cmd' => timeout_check_command(
                $phpBin.' vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --no-ansi'
                    .(is_llm_mode() ? ' --format=json --show-progress=none' : ' --diff'),
                30,
            ),
        ],
    ];

    // DB schema must be ready before the test / controller / llm-real
    // steps start.  Migrate once (fast, idempotent).
    @mkdir('var/test', 0755, true);
    $migrate = run_quiet_command(
        'APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate->getExitCode()) {
        fail_quality('test database migration failed: '.$migrate->getErrorOutput());
    }

    // Fail-fast: verify llama.cpp can actually generate before burning
    // time on controller / llm-real / TUI E2E steps.  Health-only checks
    // are insufficient — the server can respond to /health and /v1/models
    // while generation is stuck (corrupted model load, slots busy).
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
 * Matches leaked PHAR messenger:consume / agent --controller children,
 * stale vendor/bin/phpunit processes, and orphaned castor check runs
 * rooted in the current checkout.  Safe: scoped to current project root
 * only.  Does not touch sibling worktrees, the llama.cpp server, or the
 * current castor process.  Silent when no stale workers exist.
 */
function cleanup_stale_check_workers(string $root): void
{
    $output = [];
    @exec('ps -eo pid=,args= 2>/dev/null', $output);
    if ([] === $output) {
        return;
    }

    $myPid = getmypid();
    $pharGlob = $root.'/var/tmp/phar/hatfield.phar';
    $pharPat = preg_quote($pharGlob, '/');

    $rootPat = preg_quote($root, '/');
    $pidsToKill = [];
    foreach ($output as $raw) {
        $line = trim($raw);
        if ('' === $line) {
            continue;
        }
        // Parse leading pid then command.  `ps -eo pid=,args=` produces
        // padded columns: "  1234 /usr/bin/php ..."
        if (!preg_match('/^\s*(\d+)\s+(.+)$/s', $line, $m)) {
            continue;
        }
        $pid = (int) $m[1];
        if ($pid === $myPid || $pid <= 1) {
            continue;
        }
        $cmdline = $m[2];

        // Skip processes not rooted in this checkout.
        if (!preg_match("/{$rootPat}/", $cmdline)) {
            continue;
        }

        // ── Leaked messenger consumers / agent controllers ──
        // PHAR-based E2E tests spawn messenger:consume and
        // agent --controller children with --time-limit=3600 that
        // can survive the test process.  Match by the checkout's
        // own PHAR binary path.
        if (preg_match("/{$pharPat}/", $cmdline)
            && (str_contains($cmdline, 'messenger:consume')
                || str_contains($cmdline, 'agent --controller'))) {
            $pidsToKill[] = $pid;
            continue;
        }

        // ── Stale vendor/bin/phpunit with this checkout's root ──
        if (str_contains($cmdline, 'vendor/bin/phpunit')) {
            $pidsToKill[] = $pid;
            continue;
        }

        // ── Stale castor check with this checkout's root ──
        // (The self-PID guard above prevents killing ourselves.)
        if (str_contains($cmdline, 'castor check')) {
            $pidsToKill[] = $pid;
            continue;
        }
    }

    if ([] === $pidsToKill) {
        return;
    }

    $pidList = implode(' ', $pidsToKill);
    echo "Killing stale check worker PIDs: {$pidList}\n";

    // SIGTERM first, short grace, then SIGKILL survivors.
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
    @mkdir(\CastorTasks\REPORTS_DIR, 0755, true);

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
