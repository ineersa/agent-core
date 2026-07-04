<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Symfony\Component\Process\Process;

/**
 * Shared runtime helper for tool authors providing cancellable execution paths.
 *
 * Two execution paths are exposed to match the simplicity or complexity of the tool:
 *
 * 1. {@see run()} — simple checkpoint wrapper for normal tools.
 *    Checks cancellation before and after the callback. Throws a clear
 *    RuntimeException if cancelled so ToolExecutor converts it to a structured error.
 *
 * 2. {@see runCancellableProcess()} — full polling helper for process-owning tools.
 *    Starts a Symfony Process, polls for completion, cooperatively checks the
 *    ToolContext cancellation token and a monotonic timeout deadline, and calls
 *    Process::stop($graceSeconds) on cancellation or timeout.
 *
 * Designed for use inside ToolHandlerInterface::__invoke() implementations.
 * The ambient ToolContext (populated by ToolExecutor) provides the cancellation
 * token and default timeout. When no context is active, cancellation checks are
 * skipped but the explicit $timeoutSeconds parameter still applies.
 */
final readonly class ToolRuntime
{
    /** Minimum interval between DB-backed cancellation token checks during process polling. */
    private const int CANCELLATION_POLL_INTERVAL_NS = 1_000_000_000;

    public function __construct(
        private StackToolExecutionContextAccessor $contextAccessor,
    ) {
    }

    /**
     * Execute a callback with cancellation checkpoints.
     *
     * Checks the ambient ToolContext cancellation token both before and after
     * the callback. If cancelled before execution, throws immediately. If
     * cancelled during execution (detected after the callback), throws with
     * a stale-result message.
     *
     * @param callable(): mixed $callback the tool logic to execute
     *
     * @return mixed the callback return value
     *
     * @throws \RuntimeException when cancellation is detected before or after
     *                           the callback. ToolExecutor catches this and
     *                           returns a structured error result.
     */
    public function run(callable $callback): mixed
    {
        $context = $this->contextAccessor->current();

        if (null !== $context && $context->cancellationToken()->isCancellationRequested()) {
            throw new \RuntimeException(\sprintf('Tool execution "%s" cancelled before start.', $context->toolName()));
        }

        $result = $callback();

        if (null !== $context && $context->cancellationToken()->isCancellationRequested()) {
            throw new \RuntimeException(\sprintf('A result for tool "%s" was produced but is already stale due to run cancellation.', $context->toolName()));
        }

        return $result;
    }

    /**
     * Run a Symfony Process with cancellation and timeout support.
     *
     * Starts the process, polls at $pollIntervalMicros intervals, and checks:
     * - The ambient ToolContext cancellation token (if available).
     * - A monotonic deadline computed from $timeoutSeconds or the context
     *   timeout, whichever is more specific.
     *
     * On cancellation or timeout, the process receives stop($graceSeconds)
     * (SIGTERM then SIGKILL after grace period).
     *
     * The caller should return CancellableProcessResult::toArray() from
     * their handler:
     *
     *     $result = $this->toolRuntime->runCancellableProcess($process);
     *     return $result->toArray();
     *
     * @param Process  $process            The Symfony Process to run. Must not
     *                                     have been started yet.
     * @param int      $graceSeconds       grace period for stop() before SIGKILL
     * @param int|null $timeoutSeconds     Explicit timeout in seconds.
     *                                     - null (default): use ToolContext timeoutSeconds
     *                                     - 0: immediate timeout
     *                                     - positive int: timeout after N seconds
     * @param int      $pollIntervalMicros poll interval in microseconds (default 100ms)
     *
     * @return CancellableProcessResult structured result with output and
     *                                  cancellation/timeout status
     */
    public function runCancellableProcess(
        Process $process,
        int $graceSeconds = 5,
        ?int $timeoutSeconds = null,
        int $pollIntervalMicros = 100_000,
    ): CancellableProcessResult {
        $context = $this->contextAccessor->current();
        $cancelToken = $context?->cancellationToken();

        // Determine effective timeout: explicit param > context timeout > no timeout.
        // A value of 0 means immediate timeout; null means no timeout at all.
        $effectiveTimeout = $timeoutSeconds ?? $context?->timeoutSeconds() ?? null;
        $deadline = null;
        if (null !== $effectiveTimeout) {
            $deadline = hrtime(true) + $effectiveTimeout * 1_000_000_000;
        }

        // Disable Symfony's built-in timeout/idle-timeout; we manage timing ourselves.
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->start();

        $lastCancellationPollNs = hrtime(true);

        while ($process->isRunning()) {
            // Monotonic timeout deadline before cooperative cancellation polling.
            if (null !== $deadline && hrtime(true) > $deadline) {
                $process->stop($graceSeconds);

                return new CancellableProcessResult(
                    stdout: $process->getOutput(),
                    stderr: $process->getErrorOutput(),
                    exitCode: $process->getExitCode(),
                    timedOut: true,
                );
            }

            // Throttle DB-backed cancellation checks so short-lived subprocesses
            // (e.g. read pipelines) can finish without repeated RunStore lookups.
            if (null !== $cancelToken) {
                $now = hrtime(true);
                if (($now - $lastCancellationPollNs) >= self::CANCELLATION_POLL_INTERVAL_NS) {
                    $lastCancellationPollNs = $now;
                    if ($cancelToken->isCancellationRequested()) {
                        $process->stop($graceSeconds);

                        return new CancellableProcessResult(
                            stdout: $process->getOutput(),
                            stderr: $process->getErrorOutput(),
                            exitCode: $process->getExitCode(),
                            cancelled: true,
                        );
                    }
                }
            }

            usleep($pollIntervalMicros);
        }

        return new CancellableProcessResult(
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            exitCode: $process->getExitCode(),
        );
    }
}
