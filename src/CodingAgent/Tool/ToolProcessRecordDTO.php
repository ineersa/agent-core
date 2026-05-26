<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Immutable record for a single tool process entry in the cross-process registry.
 *
 * Serialized to JSONL for storage so the controller process can see
 * foreground PIDs registered by the Messenger tool consumer.
 */
final readonly class ToolProcessRecordDTO
{
    /**
     * @param non-empty-string  $runId
     * @param non-empty-string  $toolCallId
     * @param positive-int      $pid
     * @param positive-int|null $processGroupId Process group ID (PGID) for Unix process-group termination
     * @param non-empty-string  $commandPreview Truncated command for diagnostics/debugging
     */
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $toolCallId,
        public ToolProcessKindEnum $kind,
        public int $pid,
        public ?int $processGroupId,
        public string $commandPreview,
        public string $cwd,
        public ?string $logPath,
        public \DateTimeImmutable $startedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            runId: $data['run_id'],
            turnNo: $data['turn_no'],
            toolCallId: $data['tool_call_id'],
            kind: ToolProcessKindEnum::from($data['kind']),
            pid: $data['pid'],
            processGroupId: $data['process_group_id'],
            commandPreview: $data['command_preview'],
            cwd: $data['cwd'],
            logPath: $data['log_path'],
            startedAt: new \DateTimeImmutable('@'.$data['started_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'turn_no' => $this->turnNo,
            'tool_call_id' => $this->toolCallId,
            'kind' => $this->kind->value,
            'pid' => $this->pid,
            'process_group_id' => $this->processGroupId,
            'command_preview' => $this->commandPreview,
            'cwd' => $this->cwd,
            'log_path' => $this->logPath,
            'started_at' => $this->startedAt->getTimestamp(),
        ];
    }
}
