<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Exec;

/**
 * Portable shell execution capability exposed to extensions.
 *
 * Executes a command with arguments (always as an array — never
 * shell-interpolated), returns captured stdout, stderr, exit code,
 * timedOut, and cancelled flags.
 *
 * Mirrors pi.exec({cwd, timeout}) semantics with explicit array args.
 * Host implementations must poll optional cancellationToken and stop owned
 * children on cancel/timeout rather than blocking on Process::run().
 *
 * @see ExecResultDTO
 * @see ExecOptionsDTO
 */
interface ExecInterface
{
    /**
     * Execute a shell command with the given arguments and options.
     *
     * @param string              $command The command to execute
     * @param list<string>        $args    Positional arguments (never shell-interpolated)
     * @param ExecOptionsDTO|null $options Optional execution settings (cwd, timeout, env, cancellationToken)
     *
     * @return ExecResultDTO The captured stdout, stderr, exit code, timedOut, and cancelled flags
     */
    public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO;
}
