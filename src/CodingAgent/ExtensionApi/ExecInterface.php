<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Portable shell execution capability exposed to extensions.
 *
 * Executes a command with arguments (always as an array — never
 * shell-interpolated), returns captured stdout, stderr, exit code,
 * and a timedOut flag.
 *
 * Mirrors pi.exec({cwd, timeout}) semantics with explicit array args.
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
     * @param ExecOptionsDTO|null $options Optional execution settings (cwd, timeout, env)
     *
     * @return ExecResultDTO The captured stdout, stderr, exit code, and timedOut flag
     */
    public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO;
}
