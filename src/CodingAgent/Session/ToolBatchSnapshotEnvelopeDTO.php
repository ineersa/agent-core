<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Typed on-disk envelope for one tool-batch snapshot file.
 *
 * batch_state is the real {@see ToolBatchStateDTO} graph normalized by the
 * container Serializer (no intermediate row DTOs / codec).
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
     * @return array{run_id: string, turn_no: int, step_id: string, batch_state: array<string, mixed>}
     */
    public function toArray(NormalizerInterface $normalizer): array
    {
        /** @var array<string, mixed> $batchState */
        $batchState = $normalizer->normalize($this->batchState, null, [
            AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP],
        ]);

        return [
            'run_id' => $this->runId,
            'turn_no' => $this->turnNo,
            'step_id' => $this->stepId,
            'batch_state' => $batchState,
        ];
    }

    /**
     * @param array<string, mixed> $decoded
     */
    public static function fromArray(
        array $decoded,
        string $expectedRunId,
        int $expectedTurnNo,
        string $expectedStepId,
        string $path,
        DenormalizerInterface $denormalizer,
        ValidatorInterface $validator,
    ): self {
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

        try {
            $batchState = self::denormalizeBatchState($batchStateRaw, $embeddedRunId, $turnNo, $stepId, $denormalizer, $validator);
        } catch (\UnexpectedValueException $exception) {
            throw new SessionToolBatchStoreException('Tool batch snapshot batch_state is invalid.', ['path' => $path, 'component' => 'session_tool_batch_store', 'run_id' => $expectedRunId, 'turn_no' => $expectedTurnNo, 'step_id' => $expectedStepId], $exception);
        }

        return new self(
            $embeddedRunId,
            $turnNo,
            $stepId,
            $batchState,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function denormalizeBatchState(
        array $data,
        string $runId,
        int $turnNo,
        string $stepId,
        DenormalizerInterface $denormalizer,
        ValidatorInterface $validator,
    ): ToolBatchStateDTO {
        $busDefaults = [
            'runId' => $runId,
            'turnNo' => $turnNo,
            'stepId' => $stepId,
            'attempt' => 1,
            'idempotencyKey' => '',
        ];

        try {
            $batchState = $denormalizer->denormalize($data, ToolBatchStateDTO::class, null, [
                AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP],
                AbstractNormalizer::DEFAULT_CONSTRUCTOR_ARGUMENTS => [
                    ExecuteToolCall::class => $busDefaults,
                    ToolCallResult::class => $busDefaults,
                ],
                // Nested maps: preserve keys; do not reindex.
                AbstractObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => false,
            ]);
        } catch (SerializerExceptionInterface|\TypeError|\ValueError $exception) {
            throw new \UnexpectedValueException(\sprintf('Tool batch batch_state is invalid: %s', $exception->getMessage()), 0, $exception);
        }

        if (!$batchState instanceof ToolBatchStateDTO) {
            throw new \UnexpectedValueException(\sprintf('Tool batch batch_state is invalid: expected %s, got %s.', ToolBatchStateDTO::class, get_debug_type($batchState)));
        }

        $violations = $validator->validate($batchState);
        if ($violations->count() > 0) {
            throw new \UnexpectedValueException(\sprintf('Tool batch batch_state is invalid: validation failed with %d violation(s).', $violations->count()), 0, new ValidationFailedException($batchState, $violations));
        }

        return self::rebindBusIdentity($batchState, $runId, $turnNo, $stepId);
    }

    /**
     * Reattach reconstructed bus envelope fields and stable recovery idempotency keys.
     */
    private static function rebindBusIdentity(ToolBatchStateDTO $batch, string $runId, int $turnNo, string $stepId): ToolBatchStateDTO
    {
        $calls = [];
        foreach ($batch->calls as $callId => $call) {
            if (!$call instanceof ExecuteToolCall) {
                throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s] must be %s.', (string) $callId, ExecuteToolCall::class));
            }
            $calls[$callId] = new ExecuteToolCall(
                runId: $runId,
                turnNo: $turnNo,
                stepId: $stepId,
                attempt: 1,
                idempotencyKey: hash('sha256', \sprintf('%s|%s|%s', $runId, $stepId, $call->toolCallId)),
                toolCallId: $call->toolCallId,
                toolName: $call->toolName,
                args: $call->args,
                orderIndex: $call->orderIndex,
                toolIdempotencyKey: $call->toolIdempotencyKey,
                mode: $call->mode,
                timeoutSeconds: $call->timeoutSeconds,
                maxParallelism: $call->maxParallelism,
                assistantMessage: $call->assistantMessage,
                argSchema: $call->argSchema,
                toolsRef: $call->toolsRef,
                humanInputAnswer: $call->humanInputAnswer,
                parentModel: $call->parentModel,
            );
        }

        $results = [];
        foreach ($batch->results as $toolCallId => $result) {
            if (!$result instanceof ToolCallResult) {
                throw new \UnexpectedValueException(\sprintf('Tool batch result_data[%s] must be %s.', (string) $toolCallId, ToolCallResult::class));
            }
            $results[$toolCallId] = new ToolCallResult(
                runId: $runId,
                turnNo: $turnNo,
                stepId: $stepId,
                attempt: 1,
                idempotencyKey: hash('sha256', \sprintf('%s|%s|%s', $runId, $stepId, $result->toolCallId)),
                toolCallId: $result->toolCallId,
                orderIndex: $result->orderIndex,
                result: $result->result,
                isError: $result->isError,
                error: $result->error,
            );
        }

        return new ToolBatchStateDTO(
            expectedOrder: $batch->expectedOrder,
            calls: $calls,
            pendingQueue: $batch->pendingQueue,
            inFlight: $batch->inFlight,
            results: $results,
            finalized: $batch->finalized,
            maxParallelism: $batch->maxParallelism,
            awaitingHumanInput: $batch->awaitingHumanInput,
        );
    }
}
