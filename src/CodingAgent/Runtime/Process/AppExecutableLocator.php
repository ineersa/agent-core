<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

/**
 * Resolves the executable command for running the agent binary.
 *
 * Separates app executable resolution from the runtime working directory so
 * messenger consumers and controller subprocesses always use the correct
 * binary regardless of --cwd or Hatfield project CWD.
 *
 * Implementations:
 *   - SourceTreeExecutableLocator — source checkout with bin/console at known root
 *   - PharExecutableLocator      — PHAR distribution (Phar::running())
 *   - ConfigExecutableLocator    — explicit path from Hatfield settings
 *   - ChainExecutableLocator     — ordered fallback chain
 *
 * @see SourceTreeExecutableLocator
 * @see src/CodingAgent/Runtime/Process/AGENTS.md
 */
interface AppExecutableLocator
{
    /**
     * Return the command array for running the app binary.
     *
     * For source checkout:     [PHP_BINARY, '/path/to/bin/console']
     * For PHAR:               [PHP_BINARY, '/path/to/app.phar']
     * For single-file binary: ['/path/to/binary']
     *
     * The returned array can be spread directly into a Symfony Process command.
     * Consumer process cwd is set independently by the caller (runtime CWD),
     * not derived from this path.
     *
     * @return list<string>
     */
    public function command(): array;

    /**
     * Return the absolute path to the executable script file.
     *
     * For source checkout:     '/path/to/bin/console'
     * For PHAR:               '/path/to/app.phar'
     * For single-file binary: '/path/to/binary'
     *
     * This is a convenience for file existence checks and diagnostic output.
     */
    public function path(): string;
}
