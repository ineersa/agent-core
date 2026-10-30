<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess\DTO;

/**
 * Owner-prepared native Bash identity consumed by the tool worker at launch.
 *
 * Paths and record id are allocated before ExecuteToolCall / ExecuteShellToolCall
 * leave run_control. pid and actual start remain unset until local launch.
 */
final readonly class PreparedBashLaunchDTO
{
    public function __construct(
        public int $id,
        public string $runId,
        public string $toolCallId,
        public string $command,
        public string $logPath,
        public string $statusPath,
        public ?string $deferredId,
        public ?int $deadlineNanoseconds = null,
    ) {
    }
}
