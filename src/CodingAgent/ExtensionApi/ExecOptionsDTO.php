<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Options for shell execution via ExecInterface.
 *
 * All properties are optional with sensible defaults.
 * This is a public API DTO with no dependencies on Hatfield internals.
 *
 * @see ExecInterface
 * @see ExecResultDTO
 */
final readonly class ExecOptionsDTO
{
    /**
     * @param string|null           $cwd     Working directory for the subprocess (null = inherit)
     * @param float|null            $timeout Timeout in seconds (null = no timeout)
     * @param array<string, string> $env     Environment variables merged on top of the inherited parent process environment
     */
    public function __construct(
        public ?string $cwd = null,
        public ?float $timeout = null,
        public array $env = [],
    ) {
    }
}
