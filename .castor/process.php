<?php

declare(strict_types=1);

/**
 * Process management primitives for Castor parallel execution.
 *
 * Every subprocess spawned by Castor QA tasks runs inside an isolated
 * session (via setsid -w) so the session leader can be reaped cleanly
 * on timeout, failure, or normal completion.
 *
 * ── Session vs process-group cleanup ────────────────────────
 *
 * setsid -w does NOT fork: it calls setsid() in-process then execs
 * sh -lc <cmd>.  The proc_open child PID is the session leader (SID)
 * and initial process-group leader (PGID).
 *
 * kill(-PGID) alone is insufficient: grandchildren spawned in their
 * own PGIDs (messenger:consume, agent --controller) share the same
 * SID but have different PGIDs.  Session-based cleanup via
 * _reap_session() catches ALL processes in the SID.
 *
 * =========================================================================
 * FUTURE (MAINT-05B+): ParaTest will replace the custom PHPUnit
 * sharding.  The process management primitives in this file remain
 * for coarse Castor-level lane orchestration (running independent
 * check/test lanes concurrently).
 * =========================================================================
 */

use Castor\Attribute\AsTask;

use function CastorTasks\acquire_castor_check_lock;
use function CastorTasks\castor_check_lock_is_busy;
use function CastorTasks\collect_qa_check_run_leaked_processes;
use function CastorTasks\collect_qa_check_run_leaked_tmux_sessions;
use function CastorTasks\finalize_qa_run_tui_tmux_sessions;
use function CastorTasks\inventory_qa_run_owned_tmux_sessions;
use function CastorTasks\qa_tmux_session_ownership_option;
use function CastorTasks\release_castor_check_lock;
use function CastorTasks\report_path;
use function CastorTasks\run_tmux_command_bounded;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';

// ── Shared proc_open parallel runner ──────────────────────────

/**
 * Run multiple shell commands concurrently via proc_open.
 *
 * Each command is spawned in an isolated process group via setsid -w
 * so Castor can cleanly reap the entire tree — including escaped
 * grandchildren like messenger:consume — after both timeout AND
 * normal completion.
 *
 * Pipe I/O is non-blocking and incremental: output is read during the
 * poll loop so that surviving grandchildren never cause a blocking
 * stream_get_contents hang after the direct child exits.
 *
 * @param array<string,array{cmd:string,log:string}> $commands
 * @param array<string,int>                          $timeouts Optional per-step timeout in seconds.
 *                                                             Defaults to empty (no Castor-enforced timeout).
 *
 * @return array<string,array{exitCode:int,output:string,duration:float}>
 */
function run_commands_parallel(array $commands, array $timeouts = []): array
{
    $processes = [];
    $results = [];
    $cwd = (string) getcwd();

    // Start every process immediately.
    foreach ($commands as $step => $info) {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        // Spawn inside an isolated session via setsid -w.
        // setsid -w does NOT fork: it calls setsid() in-process then
        // execs sh -lc <cmd>.  The proc_open child PID is the session
        // leader (SID) and initial process-group leader (PGID).
        //
        // kill(-PGID) alone is insufficient: grandchildren spawned in
        // their own PGIDs (messenger:consume, agent --controller) share
        // the same SID but have different PGIDs.  Session-based cleanup
        // via _reap_session() catches ALL processes in the SID.
        $process = @proc_open(
            ['setsid', '-w', 'sh', '-lc', $info['cmd']],
            $descriptors,
            $pipes,
            $cwd,
        );

        if (!is_resource($process)) {
            $results[$step] = [
                'exitCode' => -1,
                'output' => 'proc_open() failed for: '.$info['cmd'],
                'duration' => 0,
            ];
            continue;
        }

        fclose($pipes[0]); // close stdin immediately

        // Non-blocking so the poll loop never blocks on reads when
        // grandchildren hold the write end of the pipe open after the
        // direct child has exited.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // The proc_open child *is* the session leader, so its PID is
        // both SID and PGID.  We store it as $sid for session-scoped
        // cleanup that catches separate-PGID grandchildren.
        $sid = proc_get_status($process)['pid'];

        $processes[$step] = [
            'handle' => $process,
            'pipes' => [$pipes[1], $pipes[2]],
            'start' => hrtime(true),
            'log' => $info['log'] ?? null,
            'outBuf' => '',
            'errBuf' => '',
            'timedOut' => false,
            'sid' => $sid > 0 ? $sid : null,
        ];
    }

    // Poll until every process exits or Castor hard-timeout fires.
    while (count($processes) > 0) {
        $finished = [];
        $now = hrtime(true);

        foreach ($processes as $step => $pInfo) {
            // ── Non-blocking incremental output capture ──
            $chunk = @fread($pInfo['pipes'][0], 65536);
            if (false !== $chunk && '' !== $chunk) {
                $processes[$step]['outBuf'] .= $chunk;
            }
            $chunk = @fread($pInfo['pipes'][1], 65536);
            if (false !== $chunk && '' !== $chunk) {
                $processes[$step]['errBuf'] .= $chunk;
            }

            $status = proc_get_status($pInfo['handle']);
            $elapsed = ($now - $pInfo['start']) / 1e9;
            $stepTimeout = $timeouts[$step] ?? null;

            if (!$status['running']) {
                $finished[] = $step;
            } elseif (null !== $stepTimeout && $elapsed >= $stepTimeout) {
                $processes[$step]['timedOut'] = true;
                $finished[] = $step;
            }
        }

        foreach ($finished as $step) {
            $pInfo = $processes[$step];
            $elapsed = (hrtime(true) - $pInfo['start']) / 1e9;

            // ── Snapshot descendant tree BEFORE intermediate parents exit ──
            // Once proc_close() returns, intermediate parents are dead and
            // grandchildren are reparented to systemd — pgrep -P can no
            // longer find them.  Collect the full tree first, then reap.
            // Also snapshot session PIDs: session cleanup is the primary
            // mechanism for separate-PGID grandchildren that pgrep -P misses.
            $descendantPids = _collect_descendant_pids($pInfo['sid'] ?? 0);
            $sessionPids = _collect_session_pids($pInfo['sid'] ?? 0);

            // ── Close our pipe ends FIRST ──
            // Close stdout/stderr before reaping so that processes
            // holding the write end get SIGPIPE / exit faster.
            @fclose($pInfo['pipes'][0]);
            @fclose($pInfo['pipes'][1]);

            // ── Reap by SESSION FIRST (primary mechanism) ──
            // This kills ALL processes in the step's session including
            // grandchildren in separate PGIDs (messenger:consume workers,
            // agent --controller children) that kill(-PGID) misses.
            _reap_session($pInfo['sid']);

            if ($pInfo['timedOut']) {
                // ── Kill the full process group (belt-and-suspenders) ──
                _reap_process_group($pInfo['sid']);
                @proc_close($pInfo['handle']);
                $exitCode = 124; // matches GNU timeout convention
                $stepTimeout = $timeouts[$step] ?? 0;
                $output = $pInfo['outBuf'].$pInfo['errBuf']
                    ."\n[Castor hard timeout after {$stepTimeout}s]";
            } else {
                $exitCode = proc_close($pInfo['handle']);
                $output = $pInfo['outBuf'].$pInfo['errBuf'];

                // Belt-and-suspenders: PG kill for any survivors.
                _reap_process_group($pInfo['sid']);
            }

            // ── Kill any descendant that survived session + PG kills ──
            // pgrep -P descendants + ps sid scan give us the full set.
            foreach (array_merge($descendantPids, $sessionPids) as $pid) {
                @posix_kill($pid, \SIGKILL);
            }

            if (null !== $pInfo['log']) {
                @mkdir(dirname($pInfo['log']), 0755, true);
                file_put_contents($pInfo['log'], $output."\n");
            }

            $results[$step] = [
                'exitCode' => $exitCode,
                'output' => $output,
                'duration' => $elapsed,
            ];

            unset($processes[$step]);
        }

        if (count($processes) > 0) {
            usleep(50000); // 50 ms
        }
    }

    return $results;
}

// ── Process-group cleanup (belt-and-suspenders) ───────────────

/**
 * Reap all processes in a process group.
 *
 * Sends SIGTERM to the group, waits a short grace period, then sends
 * SIGKILL.  Surviving grandchildren (e.g. messenger:consume with
 * --time-limit=3600) that escaped shell/timeout/phpunit exit are
 * killed even though their original parent has already terminated.
 *
 * Falls back to _kill_descendant_tree when no PGID is available.
 */
function _reap_process_group(?int $pgid): void
{
    if (null === $pgid || $pgid <= 1) {
        return;
    }

    // Collect the full descendant tree BEFORE sending any signals.
    // Once intermediate parents exit (from kill(-PGID)), grandchild
    // pids are reparented to init/systemd and pgrep -P can no longer
    // find them.  Snapshot first, then kill everything we captured.
    $descendantPids = _collect_descendant_pids($pgid);

    // Best-effort SIGTERM to the process group.
    // kill(-pgid) targets all processes with that PGID even after
    // the original leader (setsid) has exited and children have been
    // reparented to init/systemd.
    @posix_kill(-$pgid, \SIGTERM);
    usleep(500_000); // 0.5 s grace
    @posix_kill(-$pgid, \SIGKILL);

    // Kill every captured descendant individually so grandchildren in
    // separate process groups (e.g. messenger:consume workers spawned
    // by the controller with their own PGID) are also reaped.
    foreach ($descendantPids as $pid) {
        @posix_kill($pid, \SIGKILL);
    }
}

/**
 * Recursively collect ALL descendant PIDs of a process.
 *
 * Walks pgrep -P depth-first to build a complete snapshot of the
 * process tree before intermediate parents exit.  The result includes
 * grandchildren in separate process groups (setsid, new PGID).
 *
 * @return list<int>
 */
function _collect_descendant_pids(int $pid): array
{
    if ($pid <= 1) {
        return [];
    }
    $children = _find_child_pids($pid);
    $pids = [];
    foreach ($children as $child) {
        $pids[] = $child;
        $pids = array_merge($pids, _collect_descendant_pids($child));
    }

    return $pids;
}

/**
 * Kill a process and all its descendants recursively.
 *
 * Uses pgrep -P to discover child PIDs, kills them depth-first,
 * then kills the parent.  Prevents orphaned grandchildren that
 * inherit pipe file descriptors and block proc_close/stream reads.
 */
function _kill_descendant_tree(int $pid, int $signal): void
{
    if ($pid <= 1) {
        return;
    }
    $children = _find_child_pids($pid);
    foreach ($children as $child) {
        _kill_descendant_tree($child, $signal);
    }
    @posix_kill($pid, $signal);
}

/**
 * Find immediate child PIDs of a process using pgrep.
 *
 * Returns an empty list when pgrep is unavailable or finds no children.
 *
 * @return list<int>
 */
function _find_child_pids(int $ppid): array
{
    if ($ppid <= 0) {
        return [];
    }
    $output = [];
    @exec("pgrep -P {$ppid} 2>/dev/null", $output);
    $pids = [];
    foreach ($output as $line) {
        $pid = (int) trim($line);
        if ($pid > 0) {
            $pids[] = $pid;
        }
    }

    return $pids;
}

// ── Session-scoped cleanup (primary mechanism) ──────────────

/**
 * Collect all PIDs in a session using ps -eo pid=,sid=.
 *
 * Unlike pgrep -P (parent-based tree walk), session lookup finds
 * ALL processes in a session regardless of intermediate parent
 * survival.  This catches grandchildren in separate PGIDs (e.g.
 * messenger:consume workers spawned via the controller with their
 * own setsid) that pgrep -P misses after the intermediate parent
 * exits.
 *
 * @return list<int>
 */
function _collect_session_pids(int $sid): array
{
    if ($sid <= 1) {
        return [];
    }
    $output = [];
    @exec('ps -eo pid=,sid= 2>/dev/null', $output);
    $pids = [];
    $myPid = getmypid();
    foreach ($output as $line) {
        $fields = preg_split('/\s+/', trim($line), 2);
        if (2 !== count($fields)) {
            continue;
        }
        $pid = (int) $fields[0];
        $psSid = (int) $fields[1];
        // Skip ourselves and init.
        if ($pid <= 1 || $pid === $myPid) {
            continue;
        }
        if ($psSid === $sid) {
            $pids[] = $pid;
        }
    }

    return $pids;
}

/**
 * Reap all processes in a session: SIGTERM, grace, SIGKILL.
 *
 * This is the PRIMARY cleanup mechanism.  It catches every process
 * in the step's session, including grandchildren that created their
 * own process groups (messenger:consume, agent --controller) and are
 * invisible to kill(-PGID).
 *
 * Paired with _reap_process_group() for belt-and-suspenders coverage.
 */
function _reap_session(?int $sid): void
{
    if (null === $sid || $sid <= 1) {
        return;
    }

    // Collect before signaling so we can individually kill survivors.
    $pids = _collect_session_pids($sid);

    // Best-effort SIGTERM to all session PIDs individually.
    foreach ($pids as $pid) {
        @posix_kill($pid, \SIGTERM);
    }
    usleep(500_000); // 0.5 s grace

    // SIGKILL any survivors.
    foreach ($pids as $pid) {
        @posix_kill($pid, \SIGKILL);
    }

    // Also SIGKILL via kill(0, -sid) — this sends to every process
    // in the process group whose value is $sid.  Since SID=PGID for
    // the session leader, this catches any process still lingering
    // in the leader's PG.
    @posix_kill(-$sid, \SIGKILL);
}

// ── Timeout hard-stop smoke proof task ──────────────────────

/**
 * Smoke-proof that the Castor parallel runner:
 *
 * 1. Does not hang when a child process spawns grandchildren that
 *    hold stdout/stderr pipes open after the timeout fires.
 *
 * 2. Reaps escaped grandchildren on NORMAL exit, not only timeout.
 *    Even if the step exits 0, leaked messenger:consume / controller /
 *    tmux-agent grandchildren must be killed.
 *
 * 3. Session-based cleanup (_reap_session) kills same-SID separate-PGID
 *    grandchildren (e.g. messenger:consume workers that get their own
 *    process group via job control).
 *
 * 4. Separate-SID grandchildren (e.g. setsid'd sub-processes that
 *    create their own session) are NOT supported once reparented to
 *    init/systemd after the parent exits — see the NOTE below.
 */
#[AsTask(name: 'test:timeout-hardstop', description: 'Verify Castor hard timeout + normal-exit session cleanup (smoke proofs A–H incl. PHAR + source + QA tmux ownership) without hangs')]
function test_timeout_hardstop(string $cmdOverride = ''): void
{
    echo "=== Castor timeout hard-stop + normal-exit cleanup smoke proof ===\n\n";
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $ok = true;

    // ── Test A: Timeout leak (original proof) ──────────────────
    echo "── Test A: Hard timeout kills leaked children ──\n\n";

    // Build a command that spawns a background grandchild holding
    // stdout/stderr open.  The shell `timeout` wraps a 30s timeout
    // but our Castor-level 3s timeout should fire first.
    //
    // Use `wait` to keep the shell alive while the background sleep
    // runs, so the pipe write ends remain open until the timeout kills
    // the full process tree.
    $leakyCmd = '' !== $cmdOverride
        ? $cmdOverride
        : 'timeout --kill-after=5s 30s sh -lc \'sleep 120 & echo "main done"; wait\' 2>&1';

    echo "Command under test:\n  {$leakyCmd}\n\n";

    $commands = [
        'leaky-test' => [
            'cmd' => $leakyCmd,
            'log' => report_path('check-test-timeout-hardstop.log'),
        ],
    ];

    $timeouts = ['leaky-test' => 3]; // Castor hard timeout: 3 s

    $preCount = count_alive_descendants();

    $start = hrtime(true);
    $results = run_commands_parallel($commands, $timeouts);
    $duration = (hrtime(true) - $start) / 1e9;

    $result = $results['leaky-test'] ?? ['exitCode' => -1, 'output' => 'no result', 'duration' => 0];

    echo "Result:\n";
    echo "  exitCode: {$result['exitCode']}\n";
    echo sprintf("  duration: %.2fs\n", $result['duration']);
    echo "  output: {$result['output']}\n\n";

    // 1. Must return fast (< 8 s, generous buffer above the 3 s timeout + 2 s grace + kill)
    if ($duration > 8.0) {
        echo "FAIL: runner took {$duration}s > 8s — likely hung\n";
        $ok = false;
    } else {
        echo "PASS: runner returned in {$duration}s (< 8s)\n";
    }

    // 2. Should be timeout exit code (124) or killed-by-termination (143).
    $exitOk = in_array($result['exitCode'], [124, 143], true);
    if (!$exitOk) {
        echo "FAIL: expected exit code 124 (timeout) or 143 (SIGTERM), got {$result['exitCode']}\n";
        $ok = false;
    } else {
        echo "PASS: exit code {$result['exitCode']} (timeout/SIGTERM)\n";
    }

    // 3. No orphan processes should remain (sleep / sh from the leaky command)
    usleep(1_000_000); // 1s settle
    $postCount = count_alive_descendants();
    if ($postCount > $preCount) {
        echo "FAIL: {$postCount} orphan processes remain (pre={$preCount})\n";
        $ok = false;
    } else {
        echo "PASS: no orphan processes (pre={$preCount}, post={$postCount})\n";
    }

    // ── Test B: Normal-exit leak ──────────────────────────────
    echo "\n── Test B: Normal-exit cleanup kills leaked children ──\n\n";

    // Command exits 0 immediately while leaving sleep 120 in the
    // background.  The _reap_process_group() call after proc_close
    // must kill that sleep via kill(-PGID, SIGTERM/SIGKILL).
    $exitLeakCmd = 'sh -lc \'sleep 120 & echo "exit-ok"; exit 0\'';

    echo "Command under test:\n  {$exitLeakCmd}\n\n";

    $commandsB = [
        'exit-leak-test' => [
            'cmd' => $exitLeakCmd,
            'log' => report_path('check-test-exit-leak.log'),
        ],
    ];

    $preCountB = count_alive_descendants();

    $startB = hrtime(true);
    $resultsB = run_commands_parallel($commandsB, []);
    $durationB = (hrtime(true) - $startB) / 1e9;

    $resultB = $resultsB['exit-leak-test'] ?? ['exitCode' => -1, 'output' => 'no result', 'duration' => 0];

    echo "Result:\n";
    echo "  exitCode: {$resultB['exitCode']}\n";
    echo sprintf("  duration: %.2fs\n", $resultB['duration']);
    echo "  output: {$resultB['output']}\n\n";

    // 1. Exit code must be 0 (normal success).
    if (0 !== $resultB['exitCode']) {
        echo "FAIL: expected exit code 0 (normal), got {$resultB['exitCode']}\n";
        $ok = false;
    } else {
        echo "PASS: exit code 0 (normal)\n";
    }

    // 2. Runner must return fast (< 5 s).
    if ($durationB > 5.0) {
        echo "FAIL: runner took {$durationB}s > 5s\n";
        $ok = false;
    } else {
        echo "PASS: runner returned in {$durationB}s (< 5s)\n";
    }

    // 3. No orphan sleep must survive the normal-exit cleanup.
    usleep(1_000_000); // 1s settle
    $postCountB = count_alive_descendants();
    if ($postCountB > $preCountB) {
        echo "FAIL: {$postCountB} orphan processes remain after normal exit (pre={$preCountB})\n";
        $ok = false;
    } else {
        echo "PASS: no orphan processes after normal exit (pre={$preCountB}, post={$postCountB})\n";
    }

    // ── Test C: Startup stale-worker cleanup ─────────────────
    echo "\n── Test C: Optional cleanup_stale_check_workers kills stale PHAR workers ──\n\n";

    // Spawn a fake stale process whose ps output looks like a leaked
    // messenger consumer.  Use array-form proc_open (no intermediate
    // sh -c escaping) with bash + exec -a (dash does not support it).
    // tail -f /dev/null blocks forever; extra args after -- are treated
    // as filenames (harmless; only /dev/null produces output).
    //
    // ps output: <pharPath> -f /dev/null -- messenger:consume fake --time-limit=3600
    $pharPath = $root.'/var/tmp/phar/hatfield.phar';
    $fakeArgs = ['bash', '-c', 'exec -a '.$pharPath
        .' tail -f /dev/null -- messenger:consume fake --time-limit=3600'];

    echo "Fake stale worker args:\n  ".implode(' ', $fakeArgs)."\n\n";

    $fakeProc = @proc_open($fakeArgs, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $fakePipes);
    if (!is_resource($fakeProc)) {
        echo "FAIL: could not start fake stale worker\n";
        $ok = false;
    } else {
        fclose($fakePipes[0]);
        $fakePid = proc_get_status($fakeProc)['pid'];
        echo "Fake stale worker PID: {$fakePid}\n";

        // Give the child a moment to exec.
        usleep(500_000);

        // Verify ps shows the expected shape.
        $psLine = '';
        @exec('ps -p '.$fakePid.' -o args= 2>/dev/null', $psLines);
        if ([] !== $psLines) {
            $psLine = trim($psLines[0]);
        }
        echo "ps args: {$psLine}\n";

        $preCountC = count_alive_descendants();
        echo "Pre-cleanup descendants: {$preCountC}\n";

        // Run the optional cleanup helper.
        cleanup_stale_check_workers($root);
        usleep(1_000_000); // 1 s settle

        $postCountC = count_alive_descendants();
        echo "Post-cleanup descendants: {$postCountC}\n";

        // Reap the proc handle first so zombies are cleaned up.
        // kill -9 leaves a zombie until the parent wait()s; posix_kill
        // returns true for zombies, so close the handle before checking.
        @fclose($fakePipes[1]);
        @fclose($fakePipes[2]);
        @proc_close($fakeProc);
        usleep(200_000);

        // Now check if the PID is truly gone.
        $fakeAlive = @posix_kill($fakePid, 0);
        if ($fakeAlive) {
            // Double-check: maybe it's a zombie adopted elsewhere.
            $psState = (string) @shell_exec('ps -p '.$fakePid.' -o state= 2>/dev/null');
            $isZombie = str_contains($psState, 'Z');
            if ($isZombie) {
                echo "PASS: fake stale PID {$fakePid} is zombie (cleaned); will be reaped by init\n";
            } else {
                echo "FAIL: fake stale PID {$fakePid} still alive after cleanup (state={$psState})\n";
                $ok = false;
            }
        } else {
            echo "PASS: fake stale PID {$fakePid} killed by optional cleanup\n";
        }
    }

    // ── Test C2: Startup cleanup kills stale source bin/console workers ──
    echo "\n── Test C2: Optional cleanup_stale_check_workers kills stale source workers ──\n\n";

    $consolePath = $root.'/bin/console';
    $fakeArgsC2 = ['bash', '-c', 'exec -a '.$consolePath
        .' tail -f /dev/null -- messenger:consume fake --time-limit=3600'];

    echo "Fake stale source worker args:\n  ".implode(' ', $fakeArgsC2)."\n\n";

    $fakeProcC2 = @proc_open($fakeArgsC2, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $fakePipesC2);
    if (!is_resource($fakeProcC2)) {
        echo "FAIL: could not start fake stale source worker\n";
        $ok = false;
    } else {
        fclose($fakePipesC2[0]);
        $fakePidC2 = proc_get_status($fakeProcC2)['pid'];
        echo "Fake stale source worker PID: {$fakePidC2}\n";

        usleep(500_000);

        $psLineC2 = '';
        @exec('ps -p '.$fakePidC2.' -o args= 2>/dev/null', $psLinesC2);
        if ([] !== $psLinesC2) {
            $psLineC2 = trim($psLinesC2[0]);
        }
        echo "ps args: {$psLineC2}\n";

        cleanup_stale_check_workers($root);
        usleep(1_000_000);

        @fclose($fakePipesC2[1]);
        @fclose($fakePipesC2[2]);
        @proc_close($fakeProcC2);
        usleep(200_000);

        $fakeAliveC2 = @posix_kill($fakePidC2, 0);
        if ($fakeAliveC2) {
            $psStateC2 = (string) @shell_exec('ps -p '.$fakePidC2.' -o state= 2>/dev/null');
            $isZombieC2 = str_contains($psStateC2, 'Z');
            if ($isZombieC2) {
                echo "PASS: fake stale source PID {$fakePidC2} is zombie (cleaned)\n";
            } else {
                echo "FAIL: fake stale source PID {$fakePidC2} still alive after cleanup (state={$psStateC2})\n";
                $ok = false;
            }
        } else {
            echo "PASS: fake stale source PID {$fakePidC2} killed by optional cleanup\n";
        }
    }

    // ── Test C3: Active Hatfield session workers are preserved ──
    echo "\n── Test C3: Optional cleanup preserves messenger workers with HATFIELD_SESSION_ID ──\n\n";

    $sessionIdSmoke = 'castor-smoke-active-session-208';
    $fakeArgsC3 = ['bash', '-c', 'export HATFIELD_SESSION_ID='.$sessionIdSmoke
        .'; exec -a '.$consolePath
        .' tail -f /dev/null -- messenger:consume fake --time-limit=3600'];

    echo "Fake active-session worker args:\n  ".implode(' ', $fakeArgsC3)."\n\n";

    $fakeProcC3 = @proc_open($fakeArgsC3, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $fakePipesC3);
    if (!is_resource($fakeProcC3)) {
        echo "FAIL: could not start fake active-session worker\n";
        $ok = false;
    } else {
        fclose($fakePipesC3[0]);
        $fakePidC3 = proc_get_status($fakeProcC3)['pid'];
        echo "Fake active-session worker PID: {$fakePidC3}\n";

        usleep(500_000);

        $environC3 = @file_get_contents("/proc/{$fakePidC3}/environ");
        if (false === $environC3 || !str_contains($environC3, 'HATFIELD_SESSION_ID='.$sessionIdSmoke)) {
            echo "FAIL: fake active-session worker missing HATFIELD_SESSION_ID in /proc environ\n";
            $ok = false;
        } else {
            echo "PASS: fake worker has HATFIELD_SESSION_ID in environ\n";
        }

        cleanup_stale_check_workers($root);
        usleep(500_000);

        $fakeAliveC3 = @posix_kill($fakePidC3, 0);
        if (!$fakeAliveC3) {
            echo "FAIL: fake active-session PID {$fakePidC3} was killed by optional cleanup\n";
            $ok = false;
        } else {
            echo "PASS: fake active-session PID {$fakePidC3} preserved by optional cleanup\n";
        }

        @posix_kill($fakePidC3, \SIGTERM);
        usleep(200_000);
        if (@posix_kill($fakePidC3, 0)) {
            @posix_kill($fakePidC3, \SIGKILL);
        }
        @fclose($fakePipesC3[1]);
        @fclose($fakePipesC3[2]);
        @proc_close($fakeProcC3);
        usleep(200_000);
    }

    // ── Test D: Same-SID separate-PGID grandchild cleanup (session-based) ──
    echo "\n── Test D: Session cleanup kills grandchild in separate PGID (same SID) ──\n\n";

    // Simulate the real-world case: a test (PHPUnit) spans a worker
    // (messenger:consume) that gets its own PGID but shares the SID
    // with the Castor step.  'set -m' enables bash job control so
    // the background sleep gets its own process group.
    // The step exits normally; _reap_session() must find and kill the
    // grandchild by SID even though it has a different PGID.
    $separatePgidCmd = "bash -lc 'set -m; sleep 120 & echo \"session-leak\"; exit 0'";

    echo "Command under test:\n  {$separatePgidCmd}\n\n";

    $commandsD = [
        'session-leak-test' => [
            'cmd' => $separatePgidCmd,
            'log' => report_path('check-test-session-leak.log'),
        ],
    ];

    $preCountD = count_alive_descendants();

    $startD = hrtime(true);
    $resultsD = run_commands_parallel($commandsD, []);
    $durationD = (hrtime(true) - $startD) / 1e9;

    $resultD = $resultsD['session-leak-test'] ?? ['exitCode' => -1, 'output' => 'no result', 'duration' => 0];

    echo "Result:\n";
    echo "  exitCode: {$resultD['exitCode']}\n";
    echo sprintf("  duration: %.2fs\n", $resultD['duration']);
    echo "  output: {$resultD['output']}\n\n";

    if (0 !== $resultD['exitCode']) {
        echo "FAIL: expected exit code 0 (normal), got {$resultD['exitCode']}\n";
        $ok = false;
    } else {
        echo "PASS: exit code 0 (normal)\n";
    }

    if ($durationD > 5.0) {
        echo "FAIL: runner took {$durationD}s > 5s\n";
        $ok = false;
    } else {
        echo "PASS: runner returned in {$durationD}s (< 5s)\n";
    }

    usleep(1_000_000); // 1s settle
    $postCountD = count_alive_descendants();
    if ($postCountD > $preCountD) {
        echo "FAIL: {$postCountD} orphan processes remain after session cleanup (pre={$preCountD})\n";
        $ok = false;
    } else {
        echo "PASS: no session orphans after separate-PGID same-SID cleanup (pre={$preCountD}, post={$postCountD})\n";
    }

    // NOTE: There is intentionally no separate-SID grandchild cleanup test
    // (different SID causes unreachable processes after parent exit).
    // The supported case is same-SID separate-PGID (Test D).

    // ── Test E: Live-LLM-like PHPUnit with leaked PHAR workers ──
    echo "\n── Test E: Live-LLM-like PHPUnit with leaked PHAR workers ──\n\n";

    // Regression proof for test:llm-real hardening.
    // Simulate a PHPUnit run that exits 0 while leaving behind a
    // background "messenger:consume" worker with the PHAR binary name
    // (same real-world pattern that kept the Castor task alive for
    // ~10 minutes after PHPUnit completed).  The session-based runner
    // must kill the leaked worker on normal exit so the Castor task
    // does not hang.
    $pharFake = $root.'/var/tmp/phar/hatfield.phar';
    $phpunitLeakCmd = "bash -c 'exec -a ".escapeshellarg($pharFake)
        ." tail -f /dev/null -- messenger:consume llm --time-limit=3600 & echo \"PHPUnit OK (simulated)\"; exit 0' 2>&1";

    echo "Command under test:\n  {$phpunitLeakCmd}\n\n";

    $commandsE = [
        'phpunit-leak-test' => [
            'cmd' => $phpunitLeakCmd,
            'log' => report_path('check-test-phpunit-leak.log'),
        ],
    ];

    $preCountE = count_alive_descendants();

    $startE = hrtime(true);
    $resultsE = run_commands_parallel($commandsE, []);
    $durationE = (hrtime(true) - $startE) / 1e9;

    $resultE = $resultsE['phpunit-leak-test'] ?? ['exitCode' => -1, 'output' => 'no result', 'duration' => 0];

    echo "Result:\n";
    echo "  exitCode: {$resultE['exitCode']}\n";
    echo sprintf("  duration: %.2fs\n", $resultE['duration']);
    echo "  output: {$resultE['output']}\n\n";

    // 1. Exit code must be 0 (simulated PHPUnit success).
    if (0 !== $resultE['exitCode']) {
        echo "FAIL: expected exit code 0 (PHPUnit OK), got {$resultE['exitCode']}\n";
        $ok = false;
    } else {
        echo "PASS: exit code 0 (simulated PHPUnit OK)\n";
    }

    // 2. Runner must return fast (< 5 s) — must not hang on leaked worker pipes.
    if ($durationE > 5.0) {
        echo "FAIL: runner took {$durationE}s > 5s — likely hung on leaked PHAR worker pipes\n";
        $ok = false;
    } else {
        echo "PASS: runner returned in {$durationE}s (< 5s)\n";
    }

    // 3. No leaked PHAR messenger:consume processes must remain.
    usleep(1_000_000); // 1 s settle
    $postCountE = count_alive_descendants();
    if ($postCountE > $preCountE) {
        echo "FAIL: {$postCountE} orphan processes remain after PHPUnit-like cleanup (pre={$preCountE})\n";
        $ok = false;
    } else {
        echo "PASS: no orphan PHAR workers after PHPUnit-like cleanup (pre={$preCountE}, post={$postCountE})\n";
    }

    // ── Test F: Full castor check lock serializes concurrent acquirers (cross-root) ──
    echo "\n── Test F: castor check lock serializes concurrent acquirers (cross-root) ──\n\n";

    $lockIdentityF = 'castor-check-lock-smoke-'.(string) getmypid();
    putenv('HATFIELD_CASTOR_CHECK_LOCK_IDENTITY='.$lockIdentityF);
    $_ENV['HATFIELD_CASTOR_CHECK_LOCK_IDENTITY'] = $lockIdentityF;
    $lockResourceF = \CastorTasks\castor_check_lock_resource_name($root);
    $lockDirF = \CastorTasks\castor_check_lock_directory();
    @unlink(\CastorTasks\castor_check_lock_meta_path($root));

    $altRootF = $root.'/var/tmp/castor-lock-alt-root-'.getmypid();
    if (is_dir($altRootF)) {
        @rmdir($altRootF);
    }
    $waitDurationF = null;
    if (!mkdir($altRootF, 0777, true) && !is_dir($altRootF)) {
        echo "FAIL: could not create alternate project root for lock smoke\n";
        $ok = false;
    } else {
        $phpBinF = \PHP_BINARY;
        $holderPhp = <<<'PHPCODE'
<?php
declare(strict_types=1);
$root = getenv('CASTOR_LOCK_TEST_ROOT') ?: getcwd();
$identity = getenv('CASTOR_LOCK_TEST_IDENTITY') ?: '';
putenv('HATFIELD_CASTOR_CHECK_LOCK_IDENTITY='.$identity);
$_ENV['HATFIELD_CASTOR_CHECK_LOCK_IDENTITY'] = $identity;
require $root.'/vendor/autoload.php';
require $root.'/.castor/helpers.php';
\CastorTasks\castor_check_lock_smoke_hold($root, 4.0);
PHPCODE;
        $holderScript = $root.'/var/tmp/castor-check-lock-holder-'.getmypid().'.php';
        file_put_contents($holderScript, $holderPhp);

        $holderEnvPrefix = 'CASTOR_LOCK_TEST_ROOT='.escapeshellarg($root)
            .' CASTOR_LOCK_TEST_IDENTITY='.escapeshellarg($lockIdentityF)
            .' HATFIELD_CASTOR_CHECK_LOCK_IDENTITY='.escapeshellarg($lockIdentityF);
        $launchCmd = $holderEnvPrefix.' '.escapeshellarg($phpBinF).' '.escapeshellarg($holderScript).' > /dev/null 2>&1 & echo $!';
        $holderPid = (int) trim((string) shell_exec($launchCmd));

        if ($holderPid <= 0) {
            echo "FAIL: could not start lock holder (pid={$holderPid})\n";
            $ok = false;
        } else {
            $holderReady = false;
            for ($poll = 0; $poll < 60; ++$poll) {
                if (!is_dir('/proc/'.$holderPid)) {
                    echo "FAIL: lock holder process {$holderPid} exited before acquiring lock\n";
                    $ok = false;
                    break;
                }
                if (castor_check_lock_is_busy($altRootF)) {
                    $holderReady = true;
                    break;
                }
                usleep(100_000);
            }
            if (!$holderReady && $ok) {
                echo "FAIL: lock holder never acquired shared Symfony Lock\n";
                $ok = false;
            }

            if ($ok) {
                $waitStartF = microtime(true);
                $waiterLock = acquire_castor_check_lock($altRootF);
                $waitDurationF = microtime(true) - $waitStartF;
                release_castor_check_lock($waiterLock, $altRootF);
            }

            if (is_dir('/proc/'.$holderPid)) {
                posix_kill($holderPid, \SIGTERM);
                usleep(200_000);
                @posix_kill($holderPid, \SIGKILL);
            }
            @unlink($holderScript);
            @rmdir($altRootF);

            $expectedResource = \CastorTasks\castor_check_lock_resource_name($altRootF);
            if ($ok && $lockResourceF !== $expectedResource) {
                echo "FAIL: lock resource mismatch holder={$lockResourceF} waiter={$expectedResource}\n";
                $ok = false;
            } elseif ($ok && null !== $waitDurationF && $waitDurationF < 2.5) {
                echo sprintf("FAIL: waiter acquired lock in %.2fs — expected to block behind holder (~4s)\n", $waitDurationF);
                $ok = false;
            } elseif ($ok && null !== $waitDurationF) {
                echo sprintf("PASS: alternate root blocked %.2fs on shared Symfony Lock resource %s (dir %s)\n", $waitDurationF, $expectedResource, $lockDirF);
            }
        }
    }

    // ── Test F2: Lock acquire timeout (fast override) ──
    echo "\n── Test F2: castor check lock acquire timeout ──\n\n";

    $lockIdentityF2 = 'castor-check-lock-timeout-'.(string) getmypid();
    putenv('HATFIELD_CASTOR_CHECK_LOCK_IDENTITY='.$lockIdentityF2);
    $_ENV['HATFIELD_CASTOR_CHECK_LOCK_IDENTITY'] = $lockIdentityF2;
    putenv('HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT=1');
    $_ENV['HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT'] = '1';
    @unlink(\CastorTasks\castor_check_lock_meta_path($root));

    $altRootF2 = $root.'/var/tmp/castor-lock-timeout-alt-'.getmypid();
    if (is_dir($altRootF2)) {
        @rmdir($altRootF2);
    }
    if (!mkdir($altRootF2, 0777, true) && !is_dir($altRootF2)) {
        echo "FAIL: could not create alternate project root for lock timeout smoke\n";
        $ok = false;
    } else {
        $phpBinF2 = \PHP_BINARY;
        $holderPhpF2 = <<<'PHPCODE'
<?php
declare(strict_types=1);
$root = getenv('CASTOR_LOCK_TEST_ROOT') ?: getcwd();
$identity = getenv('CASTOR_LOCK_TEST_IDENTITY') ?: '';
putenv('HATFIELD_CASTOR_CHECK_LOCK_IDENTITY='.$identity);
$_ENV['HATFIELD_CASTOR_CHECK_LOCK_IDENTITY'] = $identity;
require $root.'/vendor/autoload.php';
require $root.'/.castor/helpers.php';
\CastorTasks\castor_check_lock_smoke_hold($root, 5.0);
PHPCODE;
        $holderScriptF2 = $root.'/var/tmp/castor-check-lock-timeout-holder-'.getmypid().'.php';
        file_put_contents($holderScriptF2, $holderPhpF2);
        $holderEnvF2 = 'CASTOR_LOCK_TEST_ROOT='.escapeshellarg($root)
            .' CASTOR_LOCK_TEST_IDENTITY='.escapeshellarg($lockIdentityF2)
            .' HATFIELD_CASTOR_CHECK_LOCK_IDENTITY='.escapeshellarg($lockIdentityF2);
        $holderPidF2 = (int) trim((string) shell_exec($holderEnvF2.' '.escapeshellarg($phpBinF2).' '.escapeshellarg($holderScriptF2).' > /dev/null 2>&1 & echo $!'));
        if ($holderPidF2 <= 0) {
            echo "FAIL: could not start lock timeout holder\n";
            $ok = false;
        } else {
            $holderReadyF2 = false;
            for ($pollF2 = 0; $pollF2 < 60; ++$pollF2) {
                if (!is_dir('/proc/'.$holderPidF2)) {
                    echo "FAIL: lock timeout holder exited early\n";
                    $ok = false;
                    break;
                }
                if (castor_check_lock_is_busy($altRootF2)) {
                    $holderReadyF2 = true;
                    break;
                }
                usleep(100_000);
            }
            if ($holderReadyF2 && $ok) {
                $timeoutCaught = false;
                $timeoutMessage = '';
                try {
                    acquire_castor_check_lock($altRootF2);
                } catch (RuntimeException $e) {
                    $timeoutCaught = true;
                    $timeoutMessage = $e->getMessage();
                }
                if (!$timeoutCaught) {
                    echo "FAIL: acquire_castor_check_lock did not fail while holder still active\n";
                    $ok = false;
                } elseif (!str_contains($timeoutMessage, 'failed to acquire Symfony Lock within')) {
                    echo "FAIL: timeout message missing expected prefix\n";
                    $ok = false;
                } elseif (!str_contains($timeoutMessage, 'lock resource:')) {
                    echo "FAIL: timeout message missing lock resource\n";
                    $ok = false;
                } else {
                    echo "PASS: acquire timed out with diagnostics (holder pid {$holderPidF2})\n";
                }
            } elseif ($ok) {
                echo "FAIL: lock timeout holder never acquired lock\n";
                $ok = false;
            }
            if (is_dir('/proc/'.$holderPidF2)) {
                posix_kill($holderPidF2, \SIGTERM);
                usleep(200_000);
                @posix_kill($holderPidF2, \SIGKILL);
            }
            @unlink($holderScriptF2);
            @rmdir($altRootF2);
        }
    }
    putenv('HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT');
    unset($_ENV['HATFIELD_CASTOR_CHECK_LOCK_TIMEOUT']);

    // ── Test G: QA run id leak detection (no auto-kill) ──
    echo "\n── Test G: QA run leak detection via /proc environ ──\n\n";

    $qaRunIdG = 'qa-smoke-leak-'.getmypid();
    putenv('HATFIELD_QA_RUN_ID='.$qaRunIdG);
    $_ENV['HATFIELD_QA_RUN_ID'] = $qaRunIdG;

    $fakeArgsG = ['php', '-r', 'sleep(30);'];
    $fakeEnvG = array_merge($_ENV, ['HATFIELD_QA_RUN_ID' => $qaRunIdG]);
    $fakeProcG = @proc_open($fakeArgsG, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $fakePipesG, null, $fakeEnvG);
    $fakePidG = 0;
    if (is_resource($fakeProcG)) {
        fclose($fakePipesG[0]);
        stream_set_blocking($fakePipesG[1], false);
        stream_set_blocking($fakePipesG[2], false);
        $fakePidG = proc_get_status($fakeProcG)['pid'];
        usleep(200_000);
    }

    if ($fakePidG <= 0) {
        echo "FAIL: could not start fake QA-tagged process\n";
        $ok = false;
    } else {
        $leaksG = collect_qa_check_run_leaked_processes($qaRunIdG);
        if ([] === $leaksG) {
            echo "FAIL: leak scanner did not find fake process pid={$fakePidG}\n";
            $ok = false;
        } else {
            echo 'PASS: leak scanner found '.count($leaksG)." process(es) tagged with HATFIELD_QA_RUN_ID\n";
        }
        @posix_kill($fakePidG, \SIGTERM);
        usleep(200_000);
        @posix_kill($fakePidG, \SIGKILL);
        if (is_resource($fakeProcG)) {
            proc_close($fakeProcG);
        }
        $leaksAfterG = collect_qa_check_run_leaked_processes($qaRunIdG);
        if ([] !== $leaksAfterG) {
            echo 'FAIL: fake process still visible after cleanup: '.json_encode($leaksAfterG)."\n";
            $ok = false;
        } else {
            echo "PASS: no leaked processes after fake cleanup\n";
        }
    }

    // ── Test H: QA-run tmux ownership survives lane timeout; exact-run finalizer ──
    echo "\n── Test H: QA-run tmux finalizer after external lane timeout ──\n\n";

    $qaRunIdH = 'qa-tmux-h-'.getmypid();
    $ownedSessionH = 'hatfield-timeout-h-owned-'.getmypid();
    $controlSessionH = 'hatfield-timeout-h-control-'.getmypid();
    $optH = qa_tmux_session_ownership_option();

    run_tmux_command_bounded('kill-session -t '.escapeshellarg($ownedSessionH), 2.0);
    run_tmux_command_bounded('kill-session -t '.escapeshellarg($controlSessionH), 2.0);

    run_tmux_command_bounded('new-session -d -s '.escapeshellarg($controlSessionH).' sleep 300', 5.0);
    run_tmux_command_bounded('new-session -d -s '.escapeshellarg($ownedSessionH).' sleep 300', 5.0);
    run_tmux_command_bounded(
        'set-option -t '.escapeshellarg($ownedSessionH).' '.escapeshellarg($optH).' '.escapeshellarg($qaRunIdH),
        5.0,
    );

    $tmuxLeaksBeforeH = collect_qa_check_run_leaked_tmux_sessions($qaRunIdH);
    if (1 !== count($tmuxLeaksBeforeH) || $tmuxLeaksBeforeH[0] !== $ownedSessionH) {
        echo 'FAIL: expected exactly owned session before finalizer, got: '.json_encode($tmuxLeaksBeforeH)."\n";
        $ok = false;
    } else {
        echo "PASS: tmux leak inventory found owned session without relying on pane /proc environ\n";
    }

    // Simulate castor check after test:tui lane timeout: PHPUnit tearDown did not run.
    finalize_qa_run_tui_tmux_sessions($qaRunIdH);

    $ownedAfterH = inventory_qa_run_owned_tmux_sessions($qaRunIdH);
    if ([] !== $ownedAfterH) {
        echo 'FAIL: owned tmux session(s) remain after finalizer: '.implode(', ', $ownedAfterH)."\n";
        $ok = false;
    } else {
        echo "PASS: exact-run finalizer removed owned tmux session\n";
    }

    $listedH = run_tmux_command_bounded('list-sessions -F '.escapeshellarg('#{session_name}'), 5.0);
    $controlAliveH = false;
    $splitLinesH = preg_split('/\r\n|\r|\n/', $listedH);
    if (false === $splitLinesH) {
        $splitLinesH = [];
    }
    foreach ($splitLinesH as $lineH) {
        if (trim($lineH) === $controlSessionH) {
            $controlAliveH = true;
            break;
        }
    }
    if (!$controlAliveH) {
        echo "FAIL: unrelated control tmux session was removed\n";
        $ok = false;
    } else {
        echo "PASS: unrelated control tmux session survived exact-run cleanup\n";
    }

    run_tmux_command_bounded('kill-session -t '.escapeshellarg($controlSessionH), 5.0);
    run_tmux_command_bounded('kill-session -t '.escapeshellarg($ownedSessionH), 5.0);

    putenv('HATFIELD_QA_RUN_ID');
    unset($_ENV['HATFIELD_QA_RUN_ID']);

    if ($ok) {
        echo "\n✅ All timeout + normal-exit + PHAR/source startup-cleanup (C/C2) + session + separate-PGID + PHPUnit-leak + castor-check-lock + lock-acquire-timeout + QA-run-leak + QA-run-tmux assertions passed.\n";
    } else {
        echo "\n❌ Some assertions FAILED.\n";
        exit(1);
    }
}

/**
 * Count processes that are descendants of the current Castor process
 * or that look like leaked test artifacts (sleep, sh, messenger).
 */
function count_alive_descendants(): int
{
    $myPid = getmypid();
    $output = [];
    @exec("pgrep -P {$myPid} 2>/dev/null", $output);
    $pids = array_values(array_filter(array_map('intval', $output), static fn ($p) => $p > 0));
    // Also count any sleep/sh/messenger orphans from our CWD.
    $cwd = (string) getcwd();
    $extra = [];
    @exec('ps -eo pid=,cmd= 2>/dev/null', $extra);
    $count = count($pids);
    $pat = preg_quote($cwd, '/');
    foreach ($extra as $line) {
        if (preg_match('/sleep\s+\d+|messenger:consume|sh\s+-lc/', $line) && preg_match("/{$pat}/", $line)) {
            ++$count;
        }
    }

    return $count;
}
