<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Result DTO from ToolRuntime::runCancellableProcess().
 *
 * Provides structured access to process output, exit code, and failure mode
 * so tool handlers can return it as-is or convert to an array for the LLM.
 */
final readonly class CancellableProcessResult
{
    public function __construct(
        public string $stdout = '',
        public string $stderr = '',
        public ?int $exitCode = null,
        public bool $cancelled = false,
        public bool $timedOut = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'exit_code' => $this->exitCode,
            'cancelled' => $this->cancelled,
            'timed_out' => $this->timedOut,
        ];
    }
}
