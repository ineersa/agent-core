<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

/**
 * PHPUnit-compatible tmux test harness for TUI e2e/snapshot tests.
 *
 * Starts detached tmux sessions, sends keystrokes, captures
 * plain-text / ANSI snapshots, polls for content, and
 * normalises dynamic text (UUIDs, run IDs, absolute paths).
 *
 * Every tmux command runs through a lightweight proc_open()
 * wrapper with an explicit per-call deadline so that a stuck
 * or deadlocked tmux server cannot hang the test suite. The
 * helper uses direct proc_open + non-blocking pipes instead
 * of Symfony Process to keep per-call overhead in the <3ms
 * range (matching shell_exec) for the common fast path where
 * tmux responds within a few milliseconds.
 *
 * Sessions are killed automatically when the harness is
 * destructed or when kill() is called explicitly.
 */
final class TmuxHarness
{
    /**
     * TUI logo/block-cursor startup wait when castor check runs test:tui in
     * parallel with the full unit suite and other lanes — 10s flakes with an
     * empty pane under CPU/tmux contention.
     */
    public const float TUI_STARTUP_LOGO_TIMEOUT_PARALLEL = 20.0;

    /**
     * Generic transcript/marker/shell-output waits when test:tui runs under
     * full parallel castor check load (unit + controller-replay + llm-real).
     */
    public const float TUI_GATE_CALLBACK_TIMEOUT_PARALLEL = 20.0;

    /**
     * Per-call deadline for fast interactive tmux control commands
     * (capture, send-key, display-message, etc.). Generous enough
     * to never flake on a healthy system.
     */
    private const float TMUX_CMD_TIMEOUT = 5.0;

    /**
     * Per-call deadline for session-creation commands, which can
     * be slightly slower due to shell startup inside the pane.
     */
    private const float TMUX_SESSION_TIMEOUT = 10.0;

    /**
     * Must match CastorTasks::qa_tmux_session_ownership_option() for castor check teardown.
     */
    private const string QA_TMUX_OWNERSHIP_OPTION = '@hatfield_qa_run_id';
    private readonly string $root;
    private readonly int $pid;

    /** @var list<non-empty-string> */
    private array $sessionNames = [];

    private ?string $snapshotDir = null;

    public function __construct()
    {
        $this->root = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
        $this->pid = getmypid();
    }

    public function __destruct()
    {
        $this->killAll();
    }

    // ── availability ──────────────────────────────────────

    /**
     * @return bool true if tmux is available on the system
     */
    public static function isAvailable(): bool
    {
        $which = trim((new self())->runTmux(
            'which tmux 2>/dev/null',
            2.0,
            throwOnTimeout: false,
        ));

        return '' !== $which;
    }

    // ── session management ─────────────────────────────────

    /**
     * Start a detached tmux session with fixed dimensions.
     *
     * @param string $command shell command to run inside the session
     * @param string $prefix  unique prefix for the session name (pid appended)
     * @param int    $width   terminal columns
     * @param int    $height  terminal rows
     *
     * @return TmuxPane value object describing the created pane
     */
    public function startDetached(
        string $command,
        string $prefix = 'hatfield-e2e',
        int $width = 120,
        int $height = 40,
        ?string $cwd = null,
    ): TmuxPane {
        $qaRunId = getenv('HATFIELD_QA_RUN_ID') ?: '';
        $qaRunSegment = '' !== $qaRunId
            ? (preg_replace('/[^a-zA-Z0-9._-]/', '', $qaRunId) ?? 'qa-run')
            : '';
        if ('' !== $qaRunSegment) {
            // Session name embeds the run id for human inspection; castor check teardown
            // matches the exact id via the @hatfield_qa_run_id session option below.
            $prefix = $prefix.'-'.$qaRunSegment;
        }
        $session = \sprintf('%s-%d-%d', $prefix, $this->pid, \count($this->sessionNames));
        $this->sessionNames[] = $session;

        // Propagate HATFIELD_QA_RUN_ID into the pane shell and descendants. The global
        // tmux server may not inherit the ParaTest worker environ on abnormal lane timeout
        // (SIGKILL before tearDown), so explicit export keeps /proc leak scans attributable.
        $paneEnvPrefix = '';
        if ('' !== $qaRunId) {
            $paneEnvPrefix = 'export HATFIELD_QA_RUN_ID='.escapeshellarg($qaRunId).'; ';
        }

        $innerCmd = \sprintf(
            '%scd %s && %s',
            $paneEnvPrefix,
            escapeshellarg($cwd ?? $this->root),
            $command,
        );

        $cmd = \sprintf(
            'tmux new-session -d -P -F "#{pane_id}" -x %d -y %d -s %s -- bash -c %s 2>&1',
            $width,
            $height,
            escapeshellarg($session),
            escapeshellarg($innerCmd),
        );

        $output = $this->runTmux($cmd, self::TMUX_SESSION_TIMEOUT);
        if ('' === $output) {
            throw new \RuntimeException(\sprintf('Failed to execute tmux command: %s', $cmd));
        }

        $paneId = $output;
        if (!str_starts_with($paneId, '%')) {
            throw new \RuntimeException(\sprintf('Failed to create tmux session "%s". Output: %s', $session, $output));
        }

        if ('' !== $qaRunId) {
            $this->runTmux(
                \sprintf(
                    'tmux set-option -t %s %s %s 2>/dev/null',
                    escapeshellarg($session),
                    escapeshellarg(self::QA_TMUX_OWNERSHIP_OPTION),
                    escapeshellarg($qaRunId),
                ),
                self::TMUX_CMD_TIMEOUT,
                throwOnTimeout: false,
            );
        }

        // Some tmux servers ignore new-session -x/-y and keep the global
        // default-size (often 80x24). Force the requested deterministic size.
        $this->runTmux(
            \sprintf('tmux resize-window -t %s -x %d -y %d 2>/dev/null', escapeshellarg($session), $width, $height),
            self::TMUX_CMD_TIMEOUT,
            throwOnTimeout: false,
        );

        return new TmuxPane(
            session: $session,
            paneId: $paneId,
        );
    }

    // ── capture ────────────────────────────────────────────

    /**
     * Capture the visible pane content as plain text (ANSI stripped).
     */
    public function capturePlain(TmuxPane $pane): string
    {
        return $this->runTmux(
            \sprintf('tmux capture-pane -p -t %s 2>&1', escapeshellarg($pane->paneId)),
            self::TMUX_CMD_TIMEOUT,
            throwOnTimeout: false,
        );
    }

    /**
     * Capture pane content with terminal scrollback history as plain text.
     *
     * Unlike capturePlain() which only captures the visible portion of the
     * pane, this captures the last N lines of scrollback history. This is
     * useful when content has scrolled off the visible area due to
     * long model output (thinking blocks, verbose responses).
     *
     * @param int $lines Maximum number of history lines to capture
     */
    public function capturePlainWithHistory(TmuxPane $pane, int $lines = 1000): string
    {
        return $this->runTmux(
            \sprintf(
                'tmux capture-pane -p -S -%d -E - -t %s 2>&1',
                $lines,
                escapeshellarg($pane->paneId),
            ),
            self::TMUX_CMD_TIMEOUT,
            throwOnTimeout: false,
        );
    }

    /**
     * Capture the visible pane content with ANSI escape codes preserved.
     */
    public function captureAnsi(TmuxPane $pane): string
    {
        return $this->runTmux(
            \sprintf('tmux capture-pane -p -e -t %s 2>&1', escapeshellarg($pane->paneId)),
            self::TMUX_CMD_TIMEOUT,
            throwOnTimeout: false,
        );
    }

    /**
     * Capture pane scrollback with ANSI escape codes preserved.
     */
    public function captureAnsiWithHistory(TmuxPane $pane, int $lines = 1000): string
    {
        return $this->runTmux(
            \sprintf(
                'tmux capture-pane -p -e -S -%d -E - -t %s 2>&1',
                $lines,
                escapeshellarg($pane->paneId),
            ),
            self::TMUX_CMD_TIMEOUT,
            throwOnTimeout: false,
        );
    }

    /**
     * Point ANSI smoke artifacts at `<testProjectDir>/.hatfield/tmp/tui/smoke`.
     */
    public function setSnapshotDir(string $testProjectDir): void
    {
        $this->snapshotDir = rtrim($testProjectDir, '/').'/.hatfield/tmp/tui/smoke';
        @mkdir($this->snapshotDir, 0o777, true);
    }

    /**
     * Capture ANSI pane content into `<snapshotDir>/<tag>-<Ymd-His>.ansi`.
     */
    public function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        if (null === $this->snapshotDir) {
            throw new \RuntimeException('TmuxHarness::setSnapshotDir() must be called before saveAnsiSnapshot().');
        }

        $ansi = $this->captureAnsi($pane);
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, date('Ymd-His'));
        file_put_contents($path, $ansi);
    }

    // ── send keys ──────────────────────────────────────────

    /**
     * Send literal text to the pane (no key interpretation).
     */
    public function sendLiteral(TmuxPane $pane, string $text): void
    {
        $this->runTmux(
            \sprintf(
                'tmux send-keys -t %s -l %s',
                escapeshellarg($pane->paneId),
                escapeshellarg($text),
            ),
            self::TMUX_CMD_TIMEOUT,
            throwOnTimeout: true,
        );
    }

    /**
     * Send a tmux key name (Enter, C-c, C-d, Up, Down, etc.).
     */
    public function sendKey(TmuxPane $pane, string $key): void
    {
        $this->runTmux(
            \sprintf(
                'tmux send-keys -t %s %s',
                escapeshellarg($pane->paneId),
                escapeshellarg($key),
            ),
            self::TMUX_CMD_TIMEOUT,
            throwOnTimeout: true,
        );
    }

    public function paneExists(TmuxPane $pane): bool
    {
        $output = $this->runTmux(
            \sprintf(
                'tmux display-message -p -t %s "#{pane_id}" 2>/dev/null',
                escapeshellarg($pane->paneId),
            ),
            2.0,
            throwOnTimeout: false,
        );

        return '' !== $output && str_starts_with($output, '%');
    }

    /**
     * Poll until the pane command exits (pane/session gone) or timeout.
     *
     * Used after Ctrl+D so clean natural process exit is proven before
     * tearDown killAll() / killSession() force-cleanup fallbacks.
     */
    public function waitUntilPaneExits(TmuxPane $pane, float $timeout = 10.0): void
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if (!$this->paneExists($pane)) {
                return;
            }

            usleep(100_000); // 100ms
        }

        $panePid = 'unknown';
        try {
            $panePid = (string) $this->panePid($pane);
        } catch (\Throwable) {
            // Pane may be half-dead; keep timeout diagnostics best-effort.
        }

        throw new \RuntimeException(\sprintf('Timed out after %.1fs waiting for pane %s (session %s, pane_pid=%s) to exit cleanly after shutdown key. Force cleanup (killAll/killSession) remains tearDown-only and must not replace this wait.', $timeout, $pane->paneId, $pane->session, $panePid));
    }

    /**
     * Shell PID for the tmux pane (bash -c agent ...). Used to scope
     * descendant process discovery in transport E2E tests.
     */
    public function panePid(TmuxPane $pane): int
    {
        $output = $this->runTmux(
            \sprintf(
                'tmux display-message -p -t %s "#{pane_pid}" 2>/dev/null',
                escapeshellarg($pane->paneId),
            ),
            2.0,
            throwOnTimeout: true,
        );

        $pid = (int) trim($output);
        if ($pid <= 0) {
            throw new \RuntimeException(\sprintf('Could not read pane PID for %s (output: %s)', $pane->paneId, $output));
        }

        return $pid;
    }

    // ── polling ────────────────────────────────────────────

    /**
     * Poll the pane until it contains the given needle or timeout.
     *
     * @param TmuxPane $pane    the pane to poll
     * @param string   $needle  substring to look for
     * @param float    $timeout seconds to wait (default 10.0)
     *
     * @return string the capture that finally matched
     *
     * @throws \RuntimeException if the timeout expires without finding the needle
     */
    public function waitForCaptureContains(
        TmuxPane $pane,
        string $needle,
        float $timeout = 10.0,
        string $message = '',
    ): string {
        $deadline = microtime(true) + $timeout;
        $lastCapture = '';

        while (microtime(true) < $deadline) {
            $lastCapture = $this->capturePlain($pane);

            if (str_contains($lastCapture, $needle)) {
                return $lastCapture;
            }

            usleep(100_000); // 100ms
        }

        $diagnostics = $this->formatCaptureTimeoutDiagnostics($pane, $needle, $timeout, $lastCapture);
        if ('' !== $message) {
            throw new \RuntimeException($message.' '.$diagnostics);
        }

        throw new \RuntimeException($diagnostics);
    }

    /**
     * Single startup readiness wait: logo + idle/work status + footer.
     * Prefer this over waitForCaptureContains(█) followed by waitForTuiReadyAfterLogo().
     */
    public function waitForTuiReady(TmuxPane $pane, float $timeout = self::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL): string
    {
        return $this->waitForTuiReadyAfterLogo($pane, $timeout);
    }

    /**
     * Poll the pane's full scrollback history until it contains the given
     * needle or timeout expires.
     *
     * Unlike waitForCaptureContains() which only checks the visible portion
     * of the pane, this checks the full terminal scrollback. Use this when
     * content may have scrolled off the visible area (e.g., due to long
     * model output) but you still need to assert it exists.
     *
     * @param TmuxPane $pane    the pane to poll
     * @param float    $timeout seconds to wait (default 10.0)
     *
     * @return string the history capture that finally matched
     *
     * @throws \RuntimeException if the timeout expires without finding the needle
     */
    public function waitForTuiReadyAfterLogo(TmuxPane $pane, float $timeout = self::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL): string
    {
        return $this->waitForCallback(
            $pane,
            static fn (string $plain): bool => str_contains($plain, '█')
                && (str_contains($plain, '● idle') || str_contains($plain, '◐ Work'))
                && str_contains($plain, '◆'),
            $timeout,
            'TUI ready after logo',
            500,
        );
    }

    public function waitForCallback(
        TmuxPane $pane,
        callable $callback,
        float $timeout = 10.0,
        string $message = '',
        int $history = 1000,
    ): string {
        $deadline = microtime(true) + $timeout;
        $lastCapture = '';

        while (microtime(true) < $deadline) {
            $lastCapture = $this->capturePlainWithHistory($pane, $history);

            if ($callback($lastCapture)) {
                return $lastCapture;
            }

            usleep(100_000); // 100ms
        }

        throw new \RuntimeException(\sprintf('%s Timed out after %.1fs. Last capture (%d lines):'.'
%s', '' !== $message ? $message.' ' : '', $timeout, substr_count($lastCapture, '
') + 1, $lastCapture, ));
    }

    // ── normalisation ──────────────────────────────────────

    /**
     * Normalise dynamic text in a snapshot so it can be compared
     * deterministically against golden fixtures.
     *
     * Replacements:
     *   - UUIDs / run IDs → <run-id>
     *   - Absolute paths to the project root → <root>
     *   - CWD path in footer after ⌂ → <cwd>
     *   - Git branch in footer after ⎇ → <branch>
     *   - Elapsed time after ⏱ → ⏱ 0m
     *   - Wrapped footer lines rejoined
     *   - Date/timestamps → <timestamp>  (future; not yet applied)
     *   - Trailing blank lines trimmed
     */
    public function killAll(): void
    {
        foreach ($this->sessionNames as $session) {
            $this->runTmux(
                \sprintf(
                    'tmux kill-session -t %s 2>/dev/null',
                    escapeshellarg($session),
                ),
                self::TMUX_CMD_TIMEOUT,
                throwOnTimeout: false,
            );
        }
        $this->sessionNames = [];
    }

    // ── internal shell ─────────────────────────────────────

    /**
     * Run a tmux command through lightweight proc_open with a
     * per-call deadline.
     *
     * shell_exec() has no timeout and can hang forever if tmux
     * deadlocks. Symfony Process adds 10-30ms per call (object
     * allocation, signal registration, internal pipe management)
     * which accumulates to 20-30s across 16 TUI tests in tight
     * polling loops. This helper splits the difference: direct
     * proc_open + non-blocking pipes + short polling loop with
     * an explicit deadline. In the common case where tmux responds
     * in <5ms, the overhead is the same as shell_exec (single
     * fork/exec/wait).
     *
     * The shell (sh -c) merges stderr into stdout for commands
     * that include `2>&1` (all captures, session start). For the
     * rest stderr is drained via the separate pipe to prevent
     * buffer deadlock.
     *
     * @param string $cmd            full shell command (invoked via /bin/sh -c)
     * @param float  $timeout        seconds before the process is killed
     * @param bool   $throwOnTimeout when true, throw RuntimeException on timeout;
     *                               when false, return empty string or partial output
     *
     * @return string trimmed stdout
     *
     * @throws \RuntimeException when the process times out and throwOnTimeout is true,
     *                           or when proc_open itself fails
     */
    private function runTmux(string $cmd, float $timeout = 5.0, bool $throwOnTimeout = true): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($cmd, $descriptors, $pipes);

        if (!\is_resource($process)) {
            if ($throwOnTimeout) {
                throw new \RuntimeException('Failed to start tmux command: '.$cmd);
            }

            return '';
        }

        try {
            // Close stdin immediately — tmux commands don't read it.
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $deadline = microtime(true) + $timeout;
            $stdout = '';

            while (true) {
                $chunk = @stream_get_contents($pipes[1]);
                if (\is_string($chunk) && '' !== $chunk) {
                    $stdout .= $chunk;
                }
                // Drain stderr to prevent pipe-buffer deadlock in the child.
                @stream_get_contents($pipes[2]);

                $status = @proc_get_status($process);
                if (!($status['running'] ?? true)) {
                    // Process done — drain any last output.
                    $stdout .= (string) @stream_get_contents($pipes[1]);

                    return trim($stdout);
                }

                if (microtime(true) >= $deadline) {
                    break;
                }

                usleep(1_000); // 1 ms — matches tmux IPC latency, avoids busy-loop
            }

            // ── Timeout ──────────────────────────────────
            @proc_terminate($process, \SIGKILL);
            // Boundedly wait for SIGKILL to reap before final drain.
            $killDeadline = microtime(true) + 0.5;
            while (microtime(true) < $killDeadline) {
                $status = @proc_get_status($process);
                if (!($status['running'] ?? false)) {
                    break;
                }
                usleep(1_000);
            }
            $stdout .= (string) @stream_get_contents($pipes[1]);

            if ($throwOnTimeout) {
                $snippet = \strlen($cmd) > 300 ? substr($cmd, 0, 300).'...' : $cmd;

                throw new \RuntimeException(\sprintf('tmux command timed out after %.1fs: %s', $timeout, $snippet));
            }

            return trim($stdout);
        } finally {
            // Always close pipes and free the process resource,
            // even if an exception (unexpected) escaped the handler above.
            foreach ($pipes as $i => $pipe) {
                if ($i > 0 && \is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            if (\is_resource($process)) {
                @proc_close($process);
            }
        }
    }

    /**
     * @param non-empty-string $needle
     */
    private function formatCaptureTimeoutDiagnostics(TmuxPane $pane, string $needle, float $timeout, string $lastPlainCapture): string
    {
        $ansi = '';
        try {
            $ansi = $this->captureAnsi($pane);
        } catch (\Throwable) {
            $ansi = '[captureAnsi failed]';
        }

        return \sprintf(
            "Timed out after %.1fs waiting for needle \"%s\" in pane %s.\n".
            "Last plain capture (%d lines):\n%s\n".
            "Last ANSI capture (%d bytes):\n%s",
            $timeout,
            $needle,
            $pane->paneId,
            substr_count($lastPlainCapture, "\n") + 1,
            $lastPlainCapture,
            \strlen($ansi),
            $ansi,
        );
    }
}
