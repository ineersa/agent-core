<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Session-file boundary codec for {@see ToolBatchStateDTO} snapshot batch_state blobs.
 *
 * Normalize only when writing tool-batch JSON; denormalize + validate once on read.
 * Injected with the FrameworkBundle container serializer/validator — never construct
 * a private normalizer stack in production.
 *
 * Runtime maps keep typed {@see ExecuteToolCall}/{@see ToolCallResult}; only the
 * fixed nested rows are Serializer-backed. Bus envelope fields (run/turn/step/
 * attempt/idempotency) are reconstructed on load and never persisted.
 */
final class ToolBatchStateCodec
{
    public function __construct(
        private readonly NormalizerInterface&DenormalizerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(ToolBatchStateDTO $batchState): array
    {
        $persisted = $this->toPersistedState($batchState);

        /** @var array<string, mixed> $payload */
        $payload = $this->serializer->normalize($persisted);

        return $payload;
    }

    /**
     * @param array<string, mixed> $data Persisted batch_state blob
     *
     * @throws \UnexpectedValueException when denormalization/validation fails
     */
    public function denormalize(array $data, string $runId, int $turnNo, string $stepId): ToolBatchStateDTO
    {
        $this->assertRequiredTopLevelKeys($data);
        $this->assertRequiredCallRowKeys($data);
        $this->assertRequiredResultKeys($data);
        // Input-only compatibility: never mutate caller payload.
        $data = $this->normalizeHistoricalParentModel($data);

        try {
            $persisted = $this->serializer->denormalize($data, ToolBatchPersistedStateDTO::class);
        } catch (SerializerExceptionInterface|\TypeError|\ValueError $exception) {
            throw new \UnexpectedValueException(\sprintf('Tool batch batch_state is invalid: %s', $exception->getMessage()), 0, $exception);
        }

        if (!$persisted instanceof ToolBatchPersistedStateDTO) {
            throw new \UnexpectedValueException(\sprintf('Tool batch batch_state is invalid: expected %s, got %s.', ToolBatchPersistedStateDTO::class, get_debug_type($persisted)));
        }

        $violations = $this->validator->validate($persisted);
        if ($violations->count() > 0) {
            throw new \UnexpectedValueException(\sprintf('Tool batch batch_state is invalid: validation failed with %d violation(s).', $violations->count()), 0, new ValidationFailedException($persisted, $violations));
        }

        return $this->fromPersistedState($persisted, $runId, $turnNo, $stepId);
    }

    private function toPersistedState(ToolBatchStateDTO $batchState): ToolBatchPersistedStateDTO
    {
        $callData = [];
        foreach ($batchState->calls as $callId => $call) {
            $callData[$callId] = new ToolBatchCallRowDTO(
                toolCallId: $call->toolCallId,
                toolName: $call->toolName,
                args: $call->args,
                orderIndex: $call->orderIndex,
                mode: $call->mode,
                timeoutSeconds: $call->timeoutSeconds,
                maxParallelism: $call->maxParallelism,
                toolsRef: $call->toolsRef,
                toolIdempotencyKey: $call->toolIdempotencyKey,
                assistantMessage: $call->assistantMessage,
                argSchema: $call->argSchema,
                humanInputAnswer: $call->humanInputAnswer,
                parentModel: $call->parentModel,
            );
        }

        // result_data stores only terminal outcomes. pendingHumanInput is a bus-only
        // nonterminal field on ToolCallResult and must never be persisted here.
        $resultData = [];
        foreach ($batchState->results as $toolCallId => $result) {
            $resultData[$toolCallId] = new ToolBatchResultRowDTO(
                toolCallId: $result->toolCallId,
                orderIndex: $result->orderIndex,
                result: $result->result,
                isError: $result->isError,
                error: $result->error,
            );
        }

        return new ToolBatchPersistedStateDTO(
            expectedOrder: $batchState->expectedOrder,
            callData: $callData,
            pendingQueue: $batchState->pendingQueue,
            inFlight: $batchState->inFlight,
            resultData: $resultData,
            finalized: $batchState->finalized,
            maxParallelism: $batchState->maxParallelism,
            awaitingHumanInput: $batchState->awaitingHumanInput,
        );
    }

    private function fromPersistedState(
        ToolBatchPersistedStateDTO $persisted,
        string $runId,
        int $turnNo,
        string $stepId,
    ): ToolBatchStateDTO {
        $expectedOrder = [];
        foreach ($persisted->expectedOrder as $callId => $orderIndex) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException('Tool batch expected_order keys must be non-empty strings.');
            }
            if (!\is_int($orderIndex)) {
                throw new \UnexpectedValueException(\sprintf('Tool batch expected_order[%s] must be an integer.', $callId));
            }
            $expectedOrder[$callId] = $orderIndex;
        }

        $calls = [];
        foreach ($persisted->callData as $callId => $row) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException('Tool batch call_data keys must be non-empty strings.');
            }
            if (!$row instanceof ToolBatchCallRowDTO) {
                throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s] must be an object.', $callId));
            }
            $calls[$callId] = $this->toExecuteToolCall($runId, $turnNo, $stepId, $row);
        }

        $pendingQueue = [];
        foreach ($persisted->pendingQueue as $index => $callId) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException(\sprintf('Tool batch pending_queue[%s] must be a non-empty string.', (string) $index));
            }
            $pendingQueue[] = $callId;
        }

        $inFlight = [];
        foreach ($persisted->inFlight as $callId => $flag) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException('Tool batch in_flight keys must be non-empty strings.');
            }
            if (true !== $flag) {
                throw new \UnexpectedValueException(\sprintf('Tool batch in_flight[%s] must be true.', $callId));
            }
            $inFlight[$callId] = true;
        }

        $results = [];
        foreach ($persisted->resultData as $callId => $row) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException('Tool batch result_data keys must be non-empty strings.');
            }
            if (!$row instanceof ToolBatchResultRowDTO) {
                throw new \UnexpectedValueException(\sprintf('Tool batch result_data[%s] must be an object.', $callId));
            }
            $results[$callId] = $this->toToolCallResult($runId, $turnNo, $stepId, $row);
        }

        $awaitingHumanInput = [];
        foreach ($persisted->awaitingHumanInput as $callId => $questionId) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException('Tool batch awaiting_human_input keys must be non-empty strings.');
            }
            if (!\is_string($questionId) || '' === $questionId) {
                throw new \UnexpectedValueException(\sprintf('Tool batch awaiting_human_input[%s] must be a non-empty string question_id.', $callId));
            }
            $awaitingHumanInput[$callId] = $questionId;
        }

        return new ToolBatchStateDTO(
            expectedOrder: $expectedOrder,
            calls: $calls,
            pendingQueue: $pendingQueue,
            inFlight: $inFlight,
            results: $results,
            finalized: $persisted->finalized,
            maxParallelism: $persisted->maxParallelism,
            awaitingHumanInput: $awaitingHumanInput,
        );
    }

    /**
     * Preserve pre-Serializer required top-level keys from ToolBatchStateDTO::fromPersistedArray().
     *
     * @param array<string, mixed> $data
     */
    private function assertRequiredTopLevelKeys(array $data): void
    {
        $required = [
            'expected_order' => 'Tool batch expected_order must be an array.',
            'call_data' => 'Tool batch call_data must be an array.',
            'pending_queue' => 'Tool batch pending_queue must be an array.',
            'in_flight' => 'Tool batch in_flight must be an array.',
            'result_data' => 'Tool batch result_data must be an array.',
            'finalized' => 'Tool batch finalized must be a boolean.',
            'max_parallelism' => 'Tool batch max_parallelism must be a positive integer.',
            'awaiting_human_input' => 'Tool batch awaiting_human_input must be an array.',
        ];

        foreach ($required as $key => $message) {
            if (!\array_key_exists($key, $data)) {
                throw new \UnexpectedValueException($message);
            }
        }

        if (!\is_array($data['expected_order'])) {
            throw new \UnexpectedValueException('Tool batch expected_order must be an array.');
        }
        if (!\is_array($data['call_data'])) {
            throw new \UnexpectedValueException('Tool batch call_data must be an array.');
        }
        if (!\is_array($data['pending_queue'])) {
            throw new \UnexpectedValueException('Tool batch pending_queue must be an array.');
        }
        if (!\is_array($data['in_flight'])) {
            throw new \UnexpectedValueException('Tool batch in_flight must be an array.');
        }
        if (!\is_array($data['result_data'])) {
            throw new \UnexpectedValueException('Tool batch result_data must be an array.');
        }
        if (!\is_bool($data['finalized'])) {
            throw new \UnexpectedValueException('Tool batch finalized must be a boolean.');
        }
        if (!\is_int($data['max_parallelism']) || $data['max_parallelism'] < 1) {
            throw new \UnexpectedValueException('Tool batch max_parallelism must be a positive integer.');
        }
        if (!\is_array($data['awaiting_human_input'])) {
            throw new \UnexpectedValueException('Tool batch awaiting_human_input must be an array.');
        }
    }

    /**
     * Preserve pre-Serializer required call-row keys that constructor defaults would soft-fill.
     * orderIndex has a PHP default only to keep historical args optional and key order correct.
     *
     * @param array<string, mixed> $data
     */
    private function assertRequiredCallRowKeys(array $data): void
    {
        if (!\is_array($data['call_data'] ?? null)) {
            return;
        }

        foreach ($data['call_data'] as $callId => $callRow) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException('Tool batch call_data keys must be non-empty strings.');
            }
            if (!\is_array($callRow)) {
                throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s] must be an object.', $callId));
            }
            if (!\array_key_exists('orderIndex', $callRow) || !\is_int($callRow['orderIndex'])) {
                throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s].orderIndex must be an integer.', $callId));
            }
        }
    }

    /**
     * Preserve pre-Serializer required-key rejection for result_data.isError.
     * Serializer constructor defaults would otherwise accept missing isError as false.
     *
     * @param array<string, mixed> $data
     */
    private function assertRequiredResultKeys(array $data): void
    {
        if (!\is_array($data['result_data'] ?? null)) {
            return;
        }

        foreach ($data['result_data'] as $callId => $resultRow) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException('Tool batch result_data keys must be non-empty strings.');
            }
            if (!\is_array($resultRow)) {
                throw new \UnexpectedValueException(\sprintf('Tool batch result_data[%s] must be an object.', $callId));
            }
            if (!\array_key_exists('isError', $resultRow)) {
                throw new \UnexpectedValueException(\sprintf('Tool batch result_data[%s].isError must be a boolean.', $callId));
            }
        }
    }

    /**
     * Historical reconstructCall degraded non-string parentModel to null without rejecting the row.
     * Apply only that soft-degrade on a copied payload before Serializer typing.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeHistoricalParentModel(array $data): array
    {
        if (!\is_array($data['call_data'] ?? null)) {
            return $data;
        }

        $callData = $data['call_data'];
        $changed = false;
        foreach ($callData as $callId => $row) {
            if (!\is_array($row) || !\array_key_exists('parentModel', $row)) {
                continue;
            }
            $parentModel = $row['parentModel'];
            if (null === $parentModel || \is_string($parentModel)) {
                continue;
            }
            $row['parentModel'] = null;
            $callData[$callId] = $row;
            $changed = true;
        }

        if (!$changed) {
            return $data;
        }

        $data['call_data'] = $callData;

        return $data;
    }

    private function toExecuteToolCall(
        string $runId,
        int $turnNo,
        string $stepId,
        ToolBatchCallRowDTO $row,
    ): ExecuteToolCall {
        return new ExecuteToolCall(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s|%s', $runId, $stepId, $row->toolCallId)),
            toolCallId: $row->toolCallId,
            toolName: $row->toolName,
            args: $row->args,
            orderIndex: $row->orderIndex,
            toolIdempotencyKey: $row->toolIdempotencyKey,
            mode: $row->mode,
            timeoutSeconds: $row->timeoutSeconds,
            maxParallelism: $row->maxParallelism,
            assistantMessage: $row->assistantMessage,
            argSchema: $row->argSchema,
            toolsRef: $row->toolsRef,
            humanInputAnswer: $row->humanInputAnswer,
            parentModel: $row->parentModel,
        );
    }

    private function toToolCallResult(
        string $runId,
        int $turnNo,
        string $stepId,
        ToolBatchResultRowDTO $row,
    ): ToolCallResult {
        return new ToolCallResult(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s|%s', $runId, $stepId, $row->toolCallId)),
            toolCallId: $row->toolCallId,
            orderIndex: $row->orderIndex,
            result: $row->result,
            isError: $row->isError,
            error: $row->error,
        );
    }
}
