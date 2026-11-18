<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Claude;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Synchronous, timeout-bounded ExecInterface for the Claude Code plugin.
 *
 * Its only caller is the Git worktree check, which never passes a
 * cancellation token, so there is no cooperative cancellation polling.
 * Children get their own pipes and cannot write into the MCP stream.
 */
final readonly class ProcessExec implements ExecInterface
{
    public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
    {
        $options ??= new ExecOptionsDTO();
        // Process treats 0 as "no timeout"; an exhausted budget must still stop the child.
        $process = new Process([$command, ...$args], $options->cwd, $options->env, null, null === $options->timeout ? null : max(0.001, $options->timeout));
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // Process already stopped the child; report it as a structured timeout.
            return new ExecResultDTO($process->getOutput(), $process->getErrorOutput(), -1, timedOut: true);
        } catch (ProcessExceptionInterface $exception) {
            // Start failures are a structured result, as in Hatfield's host.
            return new ExecResultDTO('', $exception->getMessage(), -1);
        }

        return new ExecResultDTO($process->getOutput(), $process->getErrorOutput(), $process->getExitCode() ?? -1);
    }
}
