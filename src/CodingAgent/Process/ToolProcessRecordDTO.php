<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Process;

/**
 * Immutable record for a single tool process entry in the cross-process registry.
 *
 * Serialized to the hatfield_tool_processes SQLite table via a normalizer/denormalizer.
 * The table schema uses snake_case column names mapped via SerializedName attributes.
 *
 * @immutable
 */
final readonly class ToolProcessRecordDTO
{
    /**
     * @param non-empty-string  $runId
     * @param positive-int      $turnNo
     * @param non-empty-string  $toolCallId
     * @param positive-int      $pid
     * @param positive-int|null $processGroupId Process group ID (PGID) for Unix process-group termination
     * @param non-empty-string  $commandPreview Truncated command for diagnostics/debugging
     * @param non-empty-string  $cwd
     */
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $toolCallId,
        public ToolProcessKindEnum $kind,
        public int $pid,
        public ?int $processGroupId = null,
        public string $commandPreview = '',
        public string $cwd = '',
        public ?string $logPath = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
    }
}
