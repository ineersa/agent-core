<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

/**
 * Canonical location of the accepted ToolExecutionEnd receipt.
 *
 * Allocated by normal event sequencing. Excluded from the semantic fingerprint.
 */
final readonly class RetainedToolCompletionSourceEventReference
{
    public function __construct(
        public string $runId,
        public int $seq,
        public int $turnNo,
        public string $type = 'tool_execution_end',
    ) {
        if ('' === trim($this->runId)) {
            throw new \InvalidArgumentException('Source event runId must be nonblank.');
        }
        if ($this->seq < 1) {
            throw new \InvalidArgumentException('Source event seq must be positive.');
        }
        if ($this->turnNo < 0) {
            throw new \InvalidArgumentException('Source event turnNo must be non-negative.');
        }
        if ('' === trim($this->type)) {
            throw new \InvalidArgumentException('Source event type must be nonblank.');
        }
    }

    /** @return array{run_id: string, seq: int, turn_no: int, type: string} */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'seq' => $this->seq,
            'turn_no' => $this->turnNo,
            'type' => $this->type,
        ];
    }
}
