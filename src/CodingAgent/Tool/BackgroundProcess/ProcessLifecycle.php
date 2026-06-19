<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Psr\Log\LoggerInterface;

/**
 * OS and filesystem operations for background process lifecycle.
 *
 * Handles process launch (setsid + shell wrapper), signal delivery
 * (TERM/KILL), liveness checks, PGID resolution, log file reading,
 * and cleanup of PID/status/log files on disk.
 *
 * Pure OS/filesystem layer — no database operations.
 */
final class ProcessLifecycle
{
    public function __construct(
        private readonly BackgroundProcessConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ─── Process launch ──────────────────────────────────────────────

    /**
     * Launch a command in a new session/process group via setsid.
     *
     * Builds a shell wrapper that backgrounds the user command, traps
     * SIGTERM, and records exit status to a status file. Returns the
     * child PID and PGID.
     *
     * @param string $command    The shell command to run. Must already be
     *                           shell-escaped if it contains user tokens.
     * @param string $pidFile    Path to the .pid file for wrapper PID record
     * @param string $logFile    Path to the .log file for stdout/stderr
     * @param string $statusFile Path to the .status file for exit code
     *
     * @return array{pid: int, pgid: int|null}
     *
     * @throws \RuntimeException on launch failure
     */
    public function launchProcess(string $command, string $pidFile, string $logFile, string $statusFile): array
    {
        // Preflight: verify setsid is available before attempting launch.
        // The shell pipeline "setsid … & echo $!" cannot detect setsid
        // failure because the shell exit code is from echo $!, not setsid.
        exec('command -v setsid', $_, $rc);
        if (0 !== $rc) {
            throw new \RuntimeException('setsid is required but not found on this platform.');
        }

        // Build a shell wrapper that:
        //  1. Records wrapper PID to pidFile
        //  2. Redirects stdout/stderr to logFile
        //  3. Backgrounds the user command as a child process (&) so
        //     the child inherits default SIGTERM (not ignored)
        //  4. Sets a SIGTERM trap in the wrapper that forwards TERM
        //     to the child, waits for it, writes the status, and exits
        //  5. Waits for the child on the normal path, stores child RC
        //     in a variable before echo so exit reflects child outcome
        $shellCode = \sprintf(
            'echo $$ > %s; exec >> %s 2>&1; %s & CHILD_PID=$!; STATUS_FILE=%s; trap \'kill -TERM $CHILD_PID 2>/dev/null; wait $CHILD_PID 2>/dev/null; echo $? > $STATUS_FILE; exit\' TERM; wait $CHILD_PID 2>/dev/null; RC=$?; echo $RC > $STATUS_FILE; exit $RC',
            escapeshellarg($pidFile),
            escapeshellarg($logFile),
            $command,
            escapeshellarg($statusFile),
        );

        // Launch with setsid (new process group) and capture PID via $!
        $launcher = 'setsid bash -c '.escapeshellarg($shellCode).' & echo $!';

        $output = [];
        $exitCode = -1;
        exec($launcher, $output, $exitCode);

        if (0 !== $exitCode || [] === $output) {
            throw new \RuntimeException('Failed to launch background process: setsid returned exit code '.$exitCode.'. (pid file: '.$pidFile.')');
        }

        $pid = (int) $output[0];
        if ($pid <= 0) {
            throw new \RuntimeException('Failed to launch background process: invalid PID ('.$output[0].').');
        }

        $pgid = $this->resolvePgid($pid);

        return ['pid' => $pid, 'pgid' => $pgid];
    }

    // ─── Signal delivery ─────────────────────────────────────────────

    /**
     * Send SIGTERM to a process (or its process group).
     */
    public function sendTerm(int $pid, ?int $pgid): void
    {
        if (null !== $pgid && $pgid > 0) {
            @exec(\sprintf('kill -TERM -%d 2>/dev/null', $pgid));
        } else {
            @exec(\sprintf('kill -TERM %d 2>/dev/null', $pid));
        }
    }

    /**
     * Send SIGKILL to a process (or its process group).
     */
    public function sendKill(int $pid, ?int $pgid): void
    {
        if (null !== $pgid && $pgid > 0) {
            @exec(\sprintf('kill -KILL -%d 2>/dev/null', $pgid));
        } else {
            @exec(\sprintf('kill -KILL %d 2>/dev/null', $pid));
        }
    }

    // ─── Liveness / status ───────────────────────────────────────────

    /**
     * Check if a process is alive by examining /proc/<pid>.
     *
     * Falls back to kill -0 when /proc is unavailable.
     */
    public function isAlive(int $pid): bool
    {
        if (is_dir('/proc/'.$pid)) {
            return true;
        }

        // Fallback: kill -0
        @exec('kill -0 '.$pid.' 2>/dev/null', $_, $exitCode);

        return 0 === $exitCode;
    }

    /**
     * Read the exit code from a .status file written by the shell wrapper.
     *
     * @return int|null Exit code, or null if the status file doesn't exist or is unreadable
     */
    public function readStatusFile(?string $statusPath): ?int
    {
        if (!\is_string($statusPath) || '' === $statusPath || !is_file($statusPath)) {
            return null;
        }

        $exitCodeRaw = @file_get_contents($statusPath);
        if (false === $exitCodeRaw) {
            // file exists but read failed — log diagnostics
            $error = error_get_last();
            if (null !== $error) {
                $this->logger->debug('background_process.status_file_unreadable', [
                    'component' => 'tool.background_process',
                    'event_type' => 'background_process.status_file_unreadable',
                    'path' => $statusPath,
                    'error' => $error['message'],
                ]);
            }

            return null;
        }

        $trimmed = trim($exitCodeRaw);
        if ('' === $trimmed) {
            return null;
        }

        return (int) $trimmed;
    }

    /**
     * Write a user-stop marker to the status file.
     *
     * Only writes when the status file doesn't already exist (meaning
     * the wrapper hasn't written a real exit code yet). For the KILL
     * path no status file is written by the wrapper, so -1 provides
     * forensic evidence that the process was forcibly stopped.
     */
    public function writeStopMarker(string $statusPath): void
    {
        if ('' === $statusPath || is_file($statusPath)) {
            return;
        }

        @file_put_contents($statusPath, (string) (-1));
    }

    // ─── PGID resolution ─────────────────────────────────────────────

    /**
     * Resolve the process group ID for a given PID.
     *
     * Retries briefly to close a startup race: after exec() returns
     * the PID, the process may not yet be visible to ps.
     */
    public function resolvePgid(int $pid): ?int
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $pgidStr = @shell_exec(\sprintf('ps -o pgid= -p %d 2>/dev/null', $pid));
            if (\is_string($pgidStr) && '' !== trim($pgidStr)) {
                $pgid = (int) trim($pgidStr);
                if ($pgid > 0) {
                    return $pgid;
                }
            }
            if ($attempt < 4) {
                usleep(50_000);
            }
        }

        return null;
    }

    // ─── Log file reading ────────────────────────────────────────────

    /**
     * Return the tail of a background process log file.
     *
     * Uses a shell command (tail -c) to read the last N bytes, avoiding
     * loading large files into PHP memory.
     */
    public function readLogTail(string $logPath, int $pid, int $maxChars): LogTailResult
    {
        if (!is_file($logPath) || !is_readable($logPath)) {
            return new LogTailResult(
                pid: $pid,
                logPath: $logPath,
                content: '(log file not found or not readable)',
                truncated: false,
                totalBytes: 0,
            );
        }

        $totalBytes = @filesize($logPath);
        if (false === $totalBytes) {
            $totalBytes = 0;
        }

        if ($totalBytes <= $maxChars) {
            $content = @file_get_contents($logPath);

            return new LogTailResult(
                pid: $pid,
                logPath: $logPath,
                content: \is_string($content) ? $content : '(failed to read log)',
                truncated: false,
                totalBytes: $totalBytes,
            );
        }

        // Read tail via shell for large files
        $tailCmd = \sprintf('tail -c %d %s 2>/dev/null', $maxChars, escapeshellarg($logPath));
        $content = @shell_exec($tailCmd);

        return new LogTailResult(
            pid: $pid,
            logPath: $logPath,
            content: \is_string($content) ? $content : '(failed to read log)',
            truncated: true,
            totalBytes: $totalBytes,
        );
    }

    /**
     * Read the full background process log file.
     *
     * Used for foreground/foreground-completed commands where the primary
     * OutputCapToolResultProcessor should see the full output and decide
     * whether to cap.  Unlike readLogTail, this does not truncate.
     *
     * @param string $logPath Absolute path to the log file
     * @param int    $pid     Process PID for result metadata
     */
    public function readLogFile(string $logPath, int $pid): LogTailResult
    {
        if (!is_file($logPath) || !is_readable($logPath)) {
            return new LogTailResult(
                pid: $pid,
                logPath: $logPath,
                content: '(log file not found or not readable)',
                truncated: false,
                totalBytes: 0,
            );
        }

        $totalBytes = @filesize($logPath);
        if (false === $totalBytes) {
            $totalBytes = 0;
        }

        $content = @file_get_contents($logPath);

        return new LogTailResult(
            pid: $pid,
            logPath: $logPath,
            content: \is_string($content) ? $content : '(failed to read log)',
            truncated: false,
            totalBytes: $totalBytes,
        );
    }

    // ─── Storage directory ───────────────────────────────────────────

    /**
     * Ensure the storage directory exists and return its resolved path.
     *
     * @throws \RuntimeException when directory cannot be created or is not writable
     */
    public function ensureStorageDir(): string
    {
        $bgDir = $this->config->storageDir;

        if (!is_dir($bgDir)) {
            $created = @mkdir($bgDir, 0750, recursive: true);
            if (!$created && !is_dir($bgDir)) {
                throw new \RuntimeException(\sprintf('Failed to create background process storage directory: %s', $bgDir));
            }
        }

        if (!is_writable($bgDir)) {
            throw new \RuntimeException(\sprintf('Background process storage directory is not writable: %s', $bgDir));
        }

        return $bgDir;
    }

    // ─── Cleanup ─────────────────────────────────────────────────────

    /**
     * Remove orphaned .pid files (and companion .status/.log files) that
     * belong to PIDs not tracked in the active set.
     *
     * @param array<int, true> $activePidSet PIDs currently tracked in the DB
     */
    public function cleanupOrphanedPidFiles(array $activePidSet): void
    {
        $bgDir = $this->config->storageDir;
        if (!is_dir($bgDir)) {
            return;
        }

        $iterator = new \FilesystemIterator($bgDir, \FilesystemIterator::SKIP_DOTS);
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();

            // Only clean .pid files
            if (!str_ends_with($filename, '.pid')) {
                continue;
            }

            $pidContent = @file_get_contents($file->getPathname());
            $pidFromFile = \is_string($pidContent) ? (int) trim($pidContent) : 0;
            if ($pidFromFile > 0 && !isset($activePidSet[$pidFromFile])) {
                // This PID is not in our active set — the .pid file is orphaned
                @unlink($file->getPathname());

                // Also clean up companion .status and .log files if they exist
                $base = $file->getPath().'/'.pathinfo($filename, \PATHINFO_FILENAME);
                foreach (['.status', '.log'] as $ext) {
                    $companion = $base.$ext;
                    if (is_file($companion)) {
                        @unlink($companion);
                    }
                }
            }
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Delete log and status files for a finished process.
     *
     * Silently ignores non-existent or empty paths.
     */
    public function deleteRecordFiles(string $logPath, string $statusPath): void
    {
        if ('' !== $logPath && is_file($logPath)) {
            @unlink($logPath);
        }
        if ('' !== $statusPath && is_file($statusPath)) {
            @unlink($statusPath);
        }
    }

    /**
     * Coerce a mixed value to ?int, handling numeric strings from SQLite.
     */
    public function coerceNullableInt(mixed $value): ?int
    {
        return null !== $value && (\is_int($value) || (\is_string($value) && ctype_digit($value))) ? (int) $value : null;
    }
}
