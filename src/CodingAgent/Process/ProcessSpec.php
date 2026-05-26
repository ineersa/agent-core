<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Process;

/**
 * Specification for starting a process via ForegroundProcessRunner.
 *
 * @immutable
 */
final readonly class ProcessSpec
{
    /**
     * @param list<string>          $command            Command and arguments (argv array)
     * @param array<string, string> $env                Environment variables to set
     * @param positive-int|null     $timeoutSeconds     Max wall-clock time before termination
     * @param bool                  $createProcessGroup Whether to create a new process group (Unix process-group safety)
     * @param non-empty-string|null $commandPreview     Truncated preview for diagnostics
     */
    public function __construct(
        public array $command,
        public string $cwd,
        public array $env = [],
        public ?int $timeoutSeconds = null,
        public bool $createProcessGroup = true,
        public ?string $commandPreview = null,
    ) {
    }

    /**
     * Convenience factory for shell commands (bash -c).
     *
     * @param array<string, string> $env
     * @param positive-int|null     $timeoutSeconds
     * @param non-empty-string|null $commandPreview
     */
    public static function shell(
        string $commandString,
        string $cwd,
        array $env = [],
        ?int $timeoutSeconds = null,
        ?string $commandPreview = null,
    ): self {
        return new self(
            command: ['bash', '-c', $commandString],
            cwd: $cwd,
            env: $env,
            timeoutSeconds: $timeoutSeconds,
            createProcessGroup: true,
            commandPreview: $commandPreview ?? mb_substr($commandString, 0, 120),
        );
    }
}
