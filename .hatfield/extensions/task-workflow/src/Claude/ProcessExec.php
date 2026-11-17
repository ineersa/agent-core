<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Claude;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Synchronous ExecInterface for the stdio MCP bridge.
 *
 * Children never inherit the MCP server's stdin/stdout: Process gives them
 * their own pipes, so hook output cannot corrupt the JSON-RPC stream.
 */
final readonly class ProcessExec implements ExecInterface
{
    private const int POLL_INTERVAL_MICROSECONDS = 50_000;

    public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
    {
        $options ??= new ExecOptionsDTO();
        $process = new Process([$command, ...$args], $options->cwd, $options->env, null, null);
        $deadlineNs = null === $options->timeout ? null : hrtime(true) + (int) (max(0.0, $options->timeout) * 1_000_000_000);
        $timedOut = false;
        $cancelled = false;

        try {
            $process->start();
            if (null === $options->cancellationToken) {
                // Nothing to poll: block until exit, bounded by Process's own timeout.
                $process->setTimeout(null === $options->timeout ? null : max(0.001, $options->timeout));
                $process->wait();
            }
            // Supervise with a monotonic deadline so a cooperative token can interleave.
            while ($process->isRunning()) {
                if (null !== $options->cancellationToken && $options->cancellationToken->isCancellationRequested()) {
                    $cancelled = true;
                    $process->stop(5);
                    break;
                }
                if (null !== $deadlineNs && hrtime(true) >= $deadlineNs) {
                    $timedOut = true;
                    $process->stop(5);
                    break;
                }
                usleep(self::POLL_INTERVAL_MICROSECONDS);
            }
        } catch (ProcessTimedOutException) {
            // Process already stopped the child; report it as a structured timeout.
            $timedOut = true;
        } catch (ProcessExceptionInterface $exception) {
            // Start/supervision failures are a structured result, as in Hatfield's host.
            return new ExecResultDTO($process->isStarted() ? $process->getOutput() : '', $exception->getMessage(), -1, $timedOut, $cancelled);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        return new ExecResultDTO(
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            exitCode: $process->getExitCode() ?? -1,
            timedOut: $timedOut,
            cancelled: $cancelled,
        );
    }
}
