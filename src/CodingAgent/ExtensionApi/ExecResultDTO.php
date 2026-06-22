<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Result of a shell command executed via ExecInterface.
 *
 * All properties are readonly. This is a public API DTO with no
 * dependencies on Hatfield internals.
 *
 * @see ExecInterface
 * @see ExecOptionsDTO
 */
final readonly class ExecResultDTO
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
        public bool $timedOut = false,
    ) {
    }
}
