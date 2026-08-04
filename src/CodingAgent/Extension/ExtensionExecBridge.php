<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * App-internal bridge implementing ExecInterface via Symfony Process.
 *
 * Executes shell commands with configurable working directory, timeout,
 * environment variables, and cooperative cancellation. Commands and
 * arguments are always passed as separate arrays — no shell interpolation.
 *
 * Timeout and cancellation are enforced by start()+poll (not Process::run())
 * so Escape/runtime cancel can stop an owned child process.
 *
 * This bridge lives in AppExtension (not ExtensionApi) because it depends
 * on Symfony Process, which is not available in the public contract layer.
 *
 * @see ExecInterface
 */
final readonly class ExtensionExecBridge implements ExecInterface
{
    private const int DEFAULT_GRACE_SECONDS = 5;
    private const int POLL_INTERVAL_MICROS = 50_000;

    public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
    {
        $options ??= new ExecOptionsDTO();

        $process = new Process([$command, ...$args], $options->cwd, $options->env);
        // Manage timeout ourselves with a monotonic deadline so cancellation can
        // interleave; Process::run() cannot poll a cooperative token mid-wait.
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $deadlineNs = null;
        if (null !== $options->timeout) {
            // Zero/negative means immediate timeout after start attempt.
            // Keep float seconds so sub-second budgets (e.g. 0.1) still work.
            $deadlineNs = hrtime(true) + (int) (max(0.0, $options->timeout) * 1_000_000_000);
        }

        try {
            $process->start();
        } catch (ProcessRuntimeException $e) {
            // proc_open failure, permission denied, etc. — structured result, not throw.
            return new ExecResultDTO(
                stdout: '',
                stderr: $e->getMessage(),
                exitCode: -1,
                timedOut: false,
                cancelled: false,
            );
        }

        $cancelled = false;
        $timedOut = false;

        try {
            while ($process->isRunning()) {
                if (null !== $options->cancellationToken && $options->cancellationToken->isCancellationRequested()) {
                    $process->stop(self::DEFAULT_GRACE_SECONDS);
                    $cancelled = true;
                    break;
                }

                if (null !== $deadlineNs && hrtime(true) >= $deadlineNs) {
                    $process->stop(self::DEFAULT_GRACE_SECONDS);
                    $timedOut = true;
                    break;
                }

                usleep(self::POLL_INTERVAL_MICROS);
            }
        } catch (ProcessRuntimeException $e) {
            return new ExecResultDTO(
                stdout: $this->safeOutput($process),
                stderr: $this->safeErrorOutput($process).$e->getMessage(),
                exitCode: -1,
                timedOut: false,
                cancelled: false,
            );
        }

        $exitCode = $process->getExitCode();
        if (null === $exitCode) {
            $exitCode = ($cancelled || $timedOut) ? -1 : 0;
        }

        return new ExecResultDTO(
            stdout: $this->safeOutput($process),
            stderr: $this->safeErrorOutput($process),
            exitCode: $exitCode,
            timedOut: $timedOut,
            cancelled: $cancelled,
        );
    }

    private function safeOutput(Process $process): string
    {
        try {
            return $process->getOutput();
        } catch (\Symfony\Component\Process\Exception\LogicException) {
            return '';
        }
    }

    private function safeErrorOutput(Process $process): string
    {
        try {
            return $process->getErrorOutput();
        } catch (\Symfony\Component\Process\Exception\LogicException) {
            return '';
        }
    }
}
