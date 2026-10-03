<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

use Ineersa\AgentCore\Contract\Tool\MalformedToolResultException;
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
            'echo $$ > %s || exit 1; echo $$; exec >> %s 2>&1; bash -c %s & CHILD_PID=$!; STATUS_FILE=%s; trap \'kill -TERM $CHILD_PID 2>/dev/null; wait $CHILD_PID 2>/dev/null; echo $? > $STATUS_FILE; exit\' TERM; wait $CHILD_PID 2>/dev/null; RC=$?; echo $RC > $STATUS_FILE; exit $RC',
            escapeshellarg($pidFile),
            escapeshellarg($logFile),
            escapeshellarg($command),
            escapeshellarg($statusFile),
        );

        // Always force setsid to fork (-f). Without -f, util-linux setsid may
        // fork only when the caller is already a process-group leader; the
        // shell's "setsid … & echo $!" then tracks that short-lived forked
        // setsid helper instead of the durable bash wrapper. The wrapper PID
        // is authoritative. Publish it on the launch pipe before redirecting
        // stdout to the log: EOF acknowledges readiness without polling a file.
        // Keep the command in its own bash -c so heredocs/exit cannot consume
        // or bypass the supervisor's wait/status-writing code.
        $launcher = 'setsid -f bash -c '.escapeshellarg($shellCode);

        $output = [];
        $exitCode = -1;
        exec($launcher, $output, $exitCode);

        if (0 !== $exitCode || [] === $output) {
            throw new \RuntimeException('Failed to launch background process: setsid returned exit code '.$exitCode.'. (pid file: '.$pidFile.')');
        }

        $pid = (int) $output[0];
        if ($pid <= 0) {
            throw new \RuntimeException('Failed to launch background process: invalid wrapper PID ('.$output[0].').');
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
     * Clears PHP's path cache before reading /proc so a prior is_dir()
     * hit does not keep a dead PID "alive". Treats zombie (state Z)
     * entries as not alive — they still occupy /proc until reaped.
     *
     * Falls back to kill -0 when /proc is unavailable.
     */
    public function isAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (is_dir('/proc')) {
            $procDir = '/proc/'.$pid;
            clearstatcache(true, $procDir);

            if (!is_dir($procDir)) {
                return false;
            }

            $stat = @file_get_contents($procDir.'/stat');
            if (false === $stat) {
                return false;
            }

            // /proc/<pid>/stat: "pid (comm) state ..." — comm may contain
            // spaces/parentheses, so state is the first token after the
            // final ')' that closes the command name.
            $closeParen = strrpos($stat, ')');
            if (false === $closeParen) {
                return false;
            }

            $state = $stat[$closeParen + 2] ?? '';

            return 'Z' !== $state;
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
     * Read one finite byte window from one open file. A growing log cannot
     * turn the whole-log path into an unbounded read after the size check.
     */
    public function readLogTail(string $logPath, int $maxChars, bool $final = true): LogTailResult
    {
        if ($maxChars < 1) {
            throw new \InvalidArgumentException('Process log read bound must be positive.');
        }
        if (!is_file($logPath) || !is_readable($logPath)) {
            return new LogTailResult(
                logPath: $logPath,
                content: '(log file not found or not readable)',
                truncated: false,
                totalBytes: 0,
            );
        }

        $file = new \SplFileObject($logPath, 'rb');
        $totalBytes = $file->fstat()['size'];
        $offset = max(0, $totalBytes - $maxChars);
        if (0 !== $file->fseek(max(0, $offset - 3))) {
            throw new \RuntimeException('Unable to seek process log.');
        }
        $prefix = $offset > 0 ? $file->fread(min(3, $offset)) : '';
        $content = $totalBytes > 0 ? $file->fread(min($maxChars, $totalBytes)) : '';
        if (false === $prefix || false === $content) {
            throw new \RuntimeException('Unable to read process log.');
        }
        if ($offset > 0) {
            // Up to three continuation bytes can belong to a character cut
            // by our left boundary. Verify against the preceding bytes before
            // dropping them, so invalid source bytes are never silently lost.
            $start = 0;
            while ($start < 3 && isset($content[$start]) && (\ord($content[$start]) & 0xC0) === 0x80) {
                ++$start;
            }
            for ($back = 1; $start > 0 && $back <= \strlen($prefix); ++$back) {
                $character = substr($prefix, -$back).substr($content, 0, $start);
                if (mb_check_encoding($character, 'UTF-8') && 1 === mb_strlen($character, 'UTF-8')) {
                    $content = substr($content, $start);
                    break;
                }
                if (!$final && $start === \strlen($content)
                    && $this->incompleteSuffixLength($character) === \strlen($character)) {
                    // Both read boundaries are inside the same live character.
                    $content = '';
                    break;
                }
            }
        }

        if (!$final) {
            // The live writer may be between bytes of one character. Withhold
            // only a legal incomplete suffix, including UTF-8's constrained
            // second-byte ranges. Subsequent reads see the suffix again.
            // mb_strcut cannot distinguish invalid source from incomplete input.
            $suffixLength = $this->incompleteSuffixLength($content);
            if ($suffixLength > 0) {
                $content = substr($content, 0, -$suffixLength);
            }
        }

        return new LogTailResult(
            logPath: $logPath,
            content: $this->displayLogText($content),
            truncated: $offset > 0,
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
     * Delete only the three exact sidecars belonging to one record. This
     * intentionally does not scan the background directory: a row may clean up
     * only its own .log/.status/.pid siblings after their paths are verified as
     * direct children of it.
     *
     * @return bool false when paths are not the expected exact sibling set or
     *              an existing sidecar cannot be removed
     */
    public function deleteExactRecordSidecars(string $logPath, string $statusPath): bool
    {
        if (!str_ends_with($logPath, '.log')) {
            return false;
        }

        $directory = \dirname($logPath);
        $prefix = substr(basename($logPath), 0, -4);
        $expectedStatusPath = $directory.'/'.$prefix.'.status';
        $expectedPidPath = $directory.'/'.$prefix.'.pid';
        if ('' === $prefix || $statusPath !== $expectedStatusPath) {
            return false;
        }

        $configuredStorageDir = $this->config->storageDir;
        if (is_dir($configuredStorageDir)) {
            $canonicalStorageDir = realpath($configuredStorageDir);
            $canonicalRecordDirectory = realpath($directory);
            if (false === $canonicalStorageDir || false === $canonicalRecordDirectory || $canonicalRecordDirectory !== $canonicalStorageDir) {
                return false;
            }
        } elseif ($directory !== $configuredStorageDir) {
            return false;
        } else {
            foreach ([$logPath, $statusPath, $expectedPidPath] as $path) {
                if (is_file($path) || is_link($path)) {
                    return false;
                }
            }

            return true;
        }

        foreach ([$logPath, $statusPath, $expectedPidPath] as $path) {
            if ((is_file($path) || is_link($path)) && !@unlink($path)) {
                return false;
            }
        }

        return true;
    }

    private function incompleteSuffixLength(string $content): int
    {
        $matched = preg_match(
            '/(?:[\xC2-\xDF]|\xE0[\xA0-\xBF]?|[\xE1-\xEC\xEE-\xEF][\x80-\xBF]?|\xED[\x80-\x9F]?|\xF0(?:[\x90-\xBF][\x80-\xBF]?)?|[\xF1-\xF3][\x80-\xBF]{0,2}|\xF4(?:[\x80-\x8F][\x80-\xBF]?)?)\z/',
            $content,
            $matches,
        );
        if (false === $matched) {
            throw new \LogicException('Invalid process log suffix pattern.');
        }

        return 1 === $matched ? \strlen($matches[0]) : 0;
    }

    private function displayLogText(string $content): string
    {
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        // Do not turn an invalid result into a successful, altered text view.
        // The process artifact and its recorded exit status remain untouched.
        throw new MalformedToolResultException();
    }
}
