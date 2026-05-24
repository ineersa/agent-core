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
 * Sessions are killed automatically when the harness is
 * destructed or when kill() is called explicitly.
 */
final class TmuxHarness
{
    private readonly string $root;
    private readonly int $pid;

    /** @var list<non-empty-string> */
    private array $sessionNames = [];

    public function __construct()
    {
        $this->root = realpath(__DIR__.'/../../..');
        $this->pid = getmypid();
    }

    // ── availability ──────────────────────────────────────

    /**
     * @return bool True if tmux is available on the system.
     */
    public static function isAvailable(): bool
    {
        $which = trim(shell_exec('which tmux 2>/dev/null') ?? '');

        return '' !== $which;
    }

    // ── session management ─────────────────────────────────

    /**
     * Start a detached tmux session with fixed dimensions.
     *
     * @param string $command  shell command to run inside the session
     * @param string $prefix   unique prefix for the session name (pid appended)
     * @param int    $width    terminal columns
     * @param int    $height   terminal rows
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
        $session = sprintf('%s-%d-%d', $prefix, $this->pid, count($this->sessionNames));
        $this->sessionNames[] = $session;

        $innerCmd = sprintf(
            'cd %s && %s',
            escapeshellarg($cwd ?? $this->root),
            $command,
        );

        $cmd = sprintf(
            'tmux new-session -d -P -F "#{pane_id}" -x %d -y %d -s %s -- bash -c %s 2>&1',
            $width,
            $height,
            escapeshellarg($session),
            escapeshellarg($innerCmd),
        );

        $output = shell_exec($cmd);
        if (null === $output) {
            throw new \RuntimeException(sprintf('Failed to execute tmux command: %s', $cmd));
        }

        $paneId = trim($output);
        if ('' === $paneId || !str_starts_with($paneId, '%')) {
            throw new \RuntimeException(sprintf(
                'Failed to create tmux session "%s". Output: %s',
                $session,
                $output,
            ));
        }

        // Some tmux servers ignore new-session -x/-y and keep the global
        // default-size (often 80x24). Force the requested deterministic size.
        shell_exec(sprintf(
            'tmux resize-window -t %s -x %d -y %d 2>/dev/null',
            escapeshellarg($session),
            $width,
            $height,
        ));

        return new TmuxPane(
            session: $session,
            paneId: $paneId,
            width: $width,
            height: $height,
        );
    }

    // ── capture ────────────────────────────────────────────

    /**
     * Capture the visible pane content as plain text (ANSI stripped).
     */
    public function capturePlain(TmuxPane $pane): string
    {
        return shell_exec(
            sprintf('tmux capture-pane -p -t %s 2>&1', escapeshellarg($pane->paneId)),
        ) ?? '';
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
        return shell_exec(
            sprintf(
                'tmux capture-pane -p -S -%d -E - -t %s 2>&1',
                $lines,
                escapeshellarg($pane->paneId),
            ),
        ) ?? '';
    }

    /**
     * Capture the visible pane content with ANSI escape codes preserved.
     */
    public function captureAnsi(TmuxPane $pane): string
    {
        return shell_exec(
            sprintf('tmux capture-pane -p -e -t %s 2>&1', escapeshellarg($pane->paneId)),
        ) ?? '';
    }

    // ── send keys ──────────────────────────────────────────

    /**
     * Send literal text to the pane (no key interpretation).
     */
    public function sendLiteral(TmuxPane $pane, string $text): void
    {
        shell_exec(sprintf(
            'tmux send-keys -t %s -l %s',
            escapeshellarg($pane->paneId),
            escapeshellarg($text),
        ));
    }

    /**
     * Send a tmux key name (Enter, C-c, C-d, Up, Down, etc.).
     */
    public function sendKey(TmuxPane $pane, string $key): void
    {
        shell_exec(sprintf(
            'tmux send-keys -t %s %s',
            escapeshellarg($pane->paneId),
            escapeshellarg($key),
        ));
    }

    public function paneExists(TmuxPane $pane): bool
    {
        $output = [];
        exec(sprintf('tmux display-message -p -t %s "#{pane_id}" 2>/dev/null', escapeshellarg($pane->paneId)), $output, $exitCode);

        return 0 === $exitCode;
    }

    // ── polling ────────────────────────────────────────────

    /**
     * Poll the pane until it contains the given needle or timeout.
     *
     * @param TmuxPane $pane    the pane to poll
     * @param string   $needle  substring to look for
     * @param float    $timeout seconds to wait (default 10.0)
     *
     * @return string The capture that finally matched.
     *
     * @throws \RuntimeException if the timeout expires without finding the needle.
     */
    public function waitForCaptureContains(
        TmuxPane $pane,
        string $needle,
        float $timeout = 10.0,
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

        throw new \RuntimeException(sprintf(
            'Timed out after %.1fs waiting for needle "%s" in pane %s. Last capture (%d lines):'."\n%s",
            $timeout,
            $needle,
            $pane->paneId,
            substr_count($lastCapture, "\n") + 1,
            $lastCapture,
        ));
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
     * @param string   $needle  substring to look for
     * @param float    $timeout seconds to wait (default 10.0)
     * @param int      $history Maximum history lines to search
     *
     * @return string The history capture that finally matched.
     *
     * @throws \RuntimeException if the timeout expires without finding the needle.
     */
    public function waitForHistoryContains(
        TmuxPane $pane,
        string $needle,
        float $timeout = 10.0,
        int $history = 1000,
    ): string {
        $deadline = microtime(true) + $timeout;
        $lastCapture = '';

        while (microtime(true) < $deadline) {
            $lastCapture = $this->capturePlainWithHistory($pane, $history);

            if (str_contains($lastCapture, $needle)) {
                return $lastCapture;
            }

            usleep(100_000); // 100ms
        }

        throw new \RuntimeException(sprintf(
            'Timed out after %.1fs waiting for needle "%s" in pane %s history. Last capture (%d lines):'."\n%s",
            $timeout,
            $needle,
            $pane->paneId,
            substr_count($lastCapture, "\n") + 1,
            $lastCapture,
        ));
    }

    // ── normalisation ──────────────────────────────────────

    /**
     * Normalise dynamic text in a snapshot so it can be compared
     * deterministically against golden fixtures.
     *
     * Replacements:
     *   - UUIDs / run IDs → <run-id>
     *   - Absolute paths to the project root → <root>
     *   - Date/timestamps → <timestamp>  (future; not yet applied)
     *   - Trailing blank lines trimmed
     */
    public function normalizeSnapshot(string $snapshot): string
    {
        // Replace run IDs (UUID v4 format)
        $snapshot = preg_replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
            '<run-id>',
            $snapshot,
        );

        // Replace arbitrary hex-looking IDs that appear as Run ID: ... (already
        // handled above, but also covers the "Started run ..." line)
        $snapshot = preg_replace(
            '/Started run <run-id>/',
            'Started run <run-id>',
            $snapshot,
        );

        // Replace 12-char hex session IDs (e.g. in footer "session a1b2c3d4e5f6")
        $snapshot = preg_replace(
            '/\bsession \b[0-9a-f]{12}\b/',
            'session <session-id>',
            $snapshot,
        );

        // Replace absolute project root paths
        $snapshot = str_replace($this->root, '<root>', $snapshot);

        // Collapse to at most one trailing newline
        $snapshot = rtrim($snapshot)."\n";

        return $snapshot;
    }

    // ── teardown ───────────────────────────────────────────

    /**
     * Kill a specific session.
     */
    public function killSession(TmuxPane $pane): void
    {
        shell_exec(sprintf(
            'tmux kill-session -t %s 2>/dev/null',
            escapeshellarg($pane->session),
        ));
        $this->sessionNames = array_values(
            array_filter($this->sessionNames, fn (string $n) => $n !== $pane->session),
        );
    }

    /**
     * Kill all sessions created by this harness instance.
     */
    public function killAll(): void
    {
        foreach ($this->sessionNames as $session) {
            shell_exec(sprintf(
                'tmux kill-session -t %s 2>/dev/null',
                escapeshellarg($session),
            ));
        }
        $this->sessionNames = [];
    }

    public function __destruct()
    {
        $this->killAll();
    }
}
