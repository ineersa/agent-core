<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Process;

/**
 * Terminates processes with TERM → grace → KILL semantics.
 *
 * A shared utility used by:
 *  - ForegroundProcessRunner: terminates timed-out or cancelled subprocesses
 *  - CancelHandler: terminates foreground tool processes on run cancellation
 *  - ConsumerSupervisor: replaces duplicated TERM→KILL shutdown logic
 *
 * Prefers Unix process-group termination (posix_kill(-$pgid, SIGTERM))
 * for tree safety, falling back to direct PID termination. Exited or
 * non-existent processes are treated as already stopped.
 *
 * The grace period for SIGTERM→SIGKILL escalation is configurable via
 * the constructor graceSeconds parameter, which should be sourced from
 * Hatfield tool settings (tools.process.termination_grace_seconds) in
 * production wiring.
 */
final class ProcessTerminator
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
        // Prefer process-group termination on Unix, but never signal our own
        // process group. Some environments ignore create_new_console/setsid;
        // sending SIGTERM/SIGKILL to the current group would kill the test
        // runner or controller along with the tool process.
        if (null !== $processGroupId && $this->canTerminateProcessGroup($processGroupId) && $this->isAlive(-$processGroupId)) {
            if ($this->sendSignal(-$processGroupId, \SIGTERM)) {
                $this->waitForExit(-$processGroupId, $this->graceSeconds);

                if ($this->isAlive(-$processGroupId)) {
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
     * Check whether a process group can be terminated safely.
     *
     * Negative PID = process group (posix_kill semantics), so only use it
     * when the target group is not the current PHP/controller process group.
     */
    private function canTerminateProcessGroup(int $processGroupId): bool
    {
        if ($processGroupId <= 0) {
            return false;
        }

        if (!\function_exists('posix_getpgrp')) {
            return true;
        }

        return $processGroupId !== posix_getpgrp();
    }

    /**
     * Check whether a process ID is alive by sending signal 0.
     *
     * Negative PID = process group (posix_kill semantics).
     * Unlike the previous abs() approach, we preserve the sign so that
     * process-group liveness checks (posix_kill(-pgid, 0)) work correctly.
     */
    private function isAlive(int $pid): bool
    {
        return @posix_kill($pid, 0);
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
