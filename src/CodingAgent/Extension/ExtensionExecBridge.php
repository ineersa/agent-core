<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\Hatfield\ExtensionApi\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\ExecResultDTO;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * App-internal bridge implementing ExecInterface via Symfony Process.
 *
 * Executes shell commands with configurable working directory, timeout,
 * and environment variables. Commands and arguments are always passed as
 * separate arrays — no shell interpolation occurs.
 *
 * This bridge lives in AppExtension (not ExtensionApi) because it depends
 * on Symfony Process, which is not available in the public contract layer.
 *
 * @see ExecInterface
 */
final readonly class ExtensionExecBridge implements ExecInterface
{
    public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
    {
        $options ??= new ExecOptionsDTO();

        $process = new Process([$command, ...$args], $options->cwd, $options->env);

        if (null !== $options->timeout) {
            $process->setTimeout($options->timeout);
        }

        try {
            $exitCode = $process->run();
            $timedOut = false;
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
        } catch (ProcessTimedOutException $e) {
            $exitCode = $e->getProcess()->getExitCode() ?? -1;
            $timedOut = true;
            $stdout = $process->getOutput();
            $stderr = $process->getErrorOutput();
        } catch (ProcessRuntimeException $e) {
            // proc_open failure, permission denied, signal, etc.
            $exitCode = -1;
            $timedOut = false;

            // getOutput/getErrorOutput throw LogicException when the
            // process never started — fall back to the exception message.
            try {
                $stdout = $process->getOutput();
                $stderr = $process->getErrorOutput().$e->getMessage();
            } catch (\Symfony\Component\Process\Exception\LogicException) {
                $stdout = '';
                $stderr = $e->getMessage();
            }
        }

        return new ExecResultDTO(
            stdout: $stdout,
            stderr: $stderr,
            exitCode: $exitCode,
            timedOut: $timedOut,
        );
    }
}
