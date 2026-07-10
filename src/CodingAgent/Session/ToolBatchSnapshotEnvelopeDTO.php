<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;

/**
 * Typed on-disk envelope for one tool-batch snapshot file.
 */
final readonly class ToolBatchSnapshotEnvelopeDTO
{
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $stepId,
        public ToolBatchStateDTO $batchState,
    ) {
    }

    public static function create(string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $batchState): self
    {
        return new self($runId, $turnNo, $stepId, $batchState);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'turn_no' => $this->turnNo,
            'step_id' => $this->stepId,
            'batch_state' => $this->batchState->toPersistedArray(),
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     */
    public static function fromArray(array $decoded, string $expectedRunId, int $expectedTurnNo, string $expectedStepId, string $path): self
    {
        $embeddedRunId = $decoded['run_id'] ?? null;
        $turnNo = $decoded['turn_no'] ?? null;
        $stepId = $decoded['step_id'] ?? null;
        $batchStateRaw = $decoded['batch_state'] ?? null;

        if (!\is_string($embeddedRunId) || '' === $embeddedRunId) {
            throw new SessionToolBatchStoreException('Tool batch snapshot missing run_id.', ['path' => $path, 'component' => 'session_tool_batch_store']);
        }

        if (!\is_int($turnNo)) {
            throw new SessionToolBatchStoreException('Tool batch snapshot missing turn_no.', ['path' => $path, 'component' => 'session_tool_batch_store', 'run_id' => $embeddedRunId]);
        }

        if (!\is_string($stepId) || '' === $stepId) {
            throw new SessionToolBatchStoreException('Tool batch snapshot missing step_id.', ['path' => $path, 'component' => 'session_tool_batch_store', 'run_id' => $embeddedRunId]);
        }

        if (!\is_array($batchStateRaw)) {
            throw new SessionToolBatchStoreException('Tool batch snapshot missing batch_state.', ['path' => $path, 'component' => 'session_tool_batch_store', 'run_id' => $embeddedRunId]);
        }

        if ($embeddedRunId !== $expectedRunId || $turnNo !== $expectedTurnNo || $stepId !== $expectedStepId) {
            throw new SessionToolBatchStoreException('Tool batch snapshot identity mismatch.', ['path' => $path, 'component' => 'session_tool_batch_store', 'run_id' => $expectedRunId, 'turn_no' => $expectedTurnNo, 'step_id' => $expectedStepId, 'embedded_run_id' => $embeddedRunId, 'embedded_turn_no' => $turnNo, 'embedded_step_id' => $stepId]);
        }

        return new self(
            $embeddedRunId,
            $turnNo,
            $stepId,
            ToolBatchStateDTO::fromPersistedArray($batchStateRaw, $embeddedRunId, $turnNo, $stepId),
        );
    }
}
