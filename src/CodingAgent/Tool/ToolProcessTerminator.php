<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Terminates tool processes with TERM → grace → KILL semantics.
 *
 * Prefers Unix process-group termination (posix_kill(-$pgid, SIGTERM))
 * for tree safety, falling back to direct PID termination. Exited or
 * non-existent processes are treated as already stopped.
 */
final class ToolProcessTerminator
{
    public const int DEFAULT_GRACE_SECONDS = 5;

    private readonly int $graceSeconds;

    public function __construct(
        ?int $graceSeconds = null,
    ) {
        $this->graceSeconds = $graceSeconds ?? self::DEFAULT_GRACE_SECONDS;
    }

    /**
     * Terminate a process by its record.
     *
     * Sends SIGTERM to the process group (preferred) or the PID directly,
     * waits for the grace period, then sends SIGKILL if the process is
     * still alive.
     *
     * @return bool Whether the process was successfully terminated (false if already gone)
     */
    public function terminate(ToolProcessRecordDTO $record): bool
    {
        return $this->terminatePid($record->pid, $record->processGroupId);
    }

    /**
     * Terminate a process by PID with optional process-group ancestry.
     *
     * @param positive-int      $pid
     * @param positive-int|null $processGroupId
     *
     * @return bool Whether the process was successfully terminated (false if already gone)
     */
    public function terminatePid(int $pid, ?int $processGroupId = null): bool
    {
        // Prefer process-group termination on Unix.
        if (null !== $processGroupId && $this->isAlive($processGroupId)) {
            if ($this->sendSignal(-$processGroupId, \SIGTERM)) {
                $this->waitForExit(-$processGroupId, $this->graceSeconds);

                if ($this->isAlive($processGroupId)) {
                    $this->sendSignal(-$processGroupId, \SIGKILL);
                }

                return true;
            }
        }

        // Fall back to direct PID termination.
        if (!$this->isAlive($pid)) {
            return false;
        }

        $this->sendSignal($pid, \SIGTERM);
        $this->waitForExit($pid, $this->graceSeconds);

        if ($this->isAlive($pid)) {
            $this->sendSignal($pid, \SIGKILL);
        }

        return true;
    }

    /**
     * Terminate multiple process records.
     *
     * @param list<ToolProcessRecordDTO> $records
     *
     * @return int Number of processes successfully terminated
     */
    public function terminateAll(array $records): int
    {
        $count = 0;

        foreach ($records as $record) {
            try {
                if ($this->terminate($record)) {
                    ++$count;
                }
            } catch (\Throwable) {
                // Best effort — skip individual failures.
            }
        }

        return $count;
    }

    /**
     * Check whether a process ID is alive by sending signal 0.
     */
    private function isAlive(int $pid): bool
    {
        // Negative PID = process group (posix_kill semantics).
        // We check with signal 0 to test existence.
        return @posix_kill(abs($pid), 0);
    }

    /**
     * Send a signal to a PID (positive) or process group (negative).
     */
    private function sendSignal(int $pid, int $signal): bool
    {
        return @posix_kill($pid, $signal);
    }

    /**
     * Busy-wait for a process/group to exit, with microsleep polling.
     */
    private function waitForExit(int $pid, int $maxSeconds): void
    {
        $deadline = microtime(true) + $maxSeconds;

        while (microtime(true) < $deadline) {
            if (!$this->isAlive($pid)) {
                return;
            }

            usleep(100_000); // 100ms poll interval
        }
    }
}
