<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Exception\LogicException as ProcessLogicException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

use function Amp\delay;

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
    private const float POLL_INTERVAL_SECONDS = 0.05;

    public function __construct(private LoggerInterface $logger = new NullLogger())
    {
    }

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
                    // Record reason before stop(): signaling failures must not erase it.
                    $cancelled = true;
                    $this->stopCooperatively($process);
                    break;
                }

                if (null !== $deadlineNs && hrtime(true) >= $deadlineNs) {
                    // Record reason before stop(): signaling failures must not erase it.
                    $timedOut = true;
                    $this->stopCooperatively($process);
                    break;
                }

                delay(self::POLL_INTERVAL_SECONDS);
            }
        } catch (ProcessRuntimeException|ProcessLogicException $e) {
            return new ExecResultDTO(
                stdout: $this->safeOutput($process),
                stderr: $this->safeErrorOutput($process).$e->getMessage(),
                exitCode: -1,
                timedOut: $timedOut,
                cancelled: $cancelled,
            );
        } finally {
            // Also reap owned work if supervision or signalling fails.
            if ($process->isRunning()) {
                $process->stop(0);
            }
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

    private function stopCooperatively(Process $process): void
    {
        // Keep Symfony's platform-specific signalling and final cleanup, but do
        // not let its synchronous grace-period polling block sibling Fibers.
        $this->signalOwnedProcess($process, 15);
        $deadline = hrtime(true) + self::DEFAULT_GRACE_SECONDS * 1_000_000_000;
        while ($process->isRunning() && hrtime(true) < $deadline) {
            delay(self::POLL_INTERVAL_SECONDS);
        }
        if ($process->isRunning()) {
            $this->signalOwnedProcess($process, 9);
            while ($process->isRunning()) {
                delay(self::POLL_INTERVAL_SECONDS);
            }
        }
        $process->stop(0);
    }

    private function signalOwnedProcess(Process $process, int $signal): void
    {
        try {
            $process->signal($signal);
        } catch (ProcessRuntimeException|ProcessLogicException $exception) {
            if ($process->isRunning()) {
                throw $exception;
            }
            // Symfony's public signal() throws when exit wins the race. Keep
            // the observed completion rather than replacing it with an error.
            $this->logger->debug('extension_exec.signal_after_exit', [
                'component' => 'extension_exec',
                'event_type' => 'extension_exec.signal_after_exit',
                'signal' => $signal,
                'exception' => $exception::class,
            ]);
        }
    }

    private function safeOutput(Process $process): string
    {
        try {
            return $process->getOutput();
        } catch (ProcessLogicException) {
            return '';
        }
    }

    private function safeErrorOutput(Process $process): string
    {
        try {
            return $process->getErrorOutput();
        } catch (ProcessLogicException) {
            return '';
        }
    }
}
