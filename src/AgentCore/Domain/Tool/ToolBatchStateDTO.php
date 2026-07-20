<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;

/**
 * Runtime and persisted tool-batch coordination state for one (run, turn, step).
 */
final class ToolBatchStateDTO
{
    /**
     * @param array<string, int>             $expectedOrder
     * @param array<string, ExecuteToolCall> $calls
     * @param list<string>                   $pendingQueue
     * @param array<string, true>            $inFlight
     * @param array<string, ToolCallResult>  $results
     * @param array<string, string>          $awaitingHumanInput tool_call_id => request question_id
     */
    public function __construct(
        public array $expectedOrder,
        public array $calls,
        public array $pendingQueue,
        public array $inFlight,
        public array $results,
        public bool $finalized,
        public int $maxParallelism,
        public array $awaitingHumanInput = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data Persisted batch_state blob from snapshot envelope
     *
     * @throws \UnexpectedValueException when required persisted fields or nested rows are malformed
     */
    public static function fromPersistedArray(array $data, string $runId, int $turnNo, string $stepId): self
    {
        if (!\array_key_exists('expected_order', $data) || !\is_array($data['expected_order'])) {
            throw new \UnexpectedValueException('Tool batch expected_order must be an array.');
        }

        $expectedOrder = [];
        foreach ($data['expected_order'] as $callId => $orderIndex) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException('Tool batch expected_order keys must be non-empty strings.');
            }
            if (!\is_int($orderIndex)) {
                throw new \UnexpectedValueException(\sprintf('Tool batch expected_order[%s] must be an integer.', $callId));
            }
            $expectedOrder[$callId] = $orderIndex;
        }

        if (!\array_key_exists('call_data', $data) || !\is_array($data['call_data'])) {
            throw new \UnexpectedValueException('Tool batch call_data must be an array.');
        }

        $calls = [];
        foreach ($data['call_data'] as $callId => $callRow) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException('Tool batch call_data keys must be non-empty strings.');
            }
            if (!\is_array($callRow)) {
                throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s] must be an object.', $callId));
            }
            self::assertCallRowShape($callId, $callRow);
            $calls[$callId] = self::reconstructCall($runId, $turnNo, $stepId, $callRow);
        }

        if (!\array_key_exists('pending_queue', $data) || !\is_array($data['pending_queue'])) {
            throw new \UnexpectedValueException('Tool batch pending_queue must be an array.');
        }

        $pendingQueue = [];
        foreach ($data['pending_queue'] as $index => $callId) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException(\sprintf('Tool batch pending_queue[%s] must be a non-empty string.', (string) $index));
            }
            $pendingQueue[] = $callId;
        }

        if (!\array_key_exists('in_flight', $data) || !\is_array($data['in_flight'])) {
            throw new \UnexpectedValueException('Tool batch in_flight must be an array.');
        }

        $inFlight = [];
        foreach ($data['in_flight'] as $callId => $flag) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException('Tool batch in_flight keys must be non-empty strings.');
            }
            if (true !== $flag) {
                throw new \UnexpectedValueException(\sprintf('Tool batch in_flight[%s] must be true.', $callId));
            }
            $inFlight[$callId] = true;
        }

        if (!\array_key_exists('result_data', $data) || !\is_array($data['result_data'])) {
            throw new \UnexpectedValueException('Tool batch result_data must be an array.');
        }

        $results = [];
        foreach ($data['result_data'] as $callId => $resultRow) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException('Tool batch result_data keys must be non-empty strings.');
            }
            if (!\is_array($resultRow)) {
                throw new \UnexpectedValueException(\sprintf('Tool batch result_data[%s] must be an object.', $callId));
            }
            self::assertResultRowShape($callId, $resultRow);
            $results[$callId] = self::reconstructResult($runId, $turnNo, $stepId, $resultRow);
        }

        if (!\array_key_exists('finalized', $data) || !\is_bool($data['finalized'])) {
            throw new \UnexpectedValueException('Tool batch finalized must be a boolean.');
        }

        if (!\array_key_exists('max_parallelism', $data) || !\is_int($data['max_parallelism']) || $data['max_parallelism'] < 1) {
            throw new \UnexpectedValueException('Tool batch max_parallelism must be a positive integer.');
        }

        if (!\array_key_exists('awaiting_human_input', $data) || !\is_array($data['awaiting_human_input'])) {
            throw new \UnexpectedValueException('Tool batch awaiting_human_input must be an array.');
        }

        $awaitingHumanInput = [];
        foreach ($data['awaiting_human_input'] as $callId => $questionId) {
            if (!\is_string($callId) || '' === $callId) {
                throw new \UnexpectedValueException('Tool batch awaiting_human_input keys must be non-empty strings.');
            }
            if (!\is_string($questionId) || '' === $questionId) {
                throw new \UnexpectedValueException(\sprintf('Tool batch awaiting_human_input[%s] must be a non-empty string question_id.', $callId));
            }
            $awaitingHumanInput[$callId] = $questionId;
        }

        return new self(
            expectedOrder: $expectedOrder,
            calls: $calls,
            pendingQueue: $pendingQueue,
            inFlight: $inFlight,
            results: $results,
            finalized: $data['finalized'],
            maxParallelism: $data['max_parallelism'],
            awaitingHumanInput: $awaitingHumanInput,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toPersistedArray(): array
    {
        $callData = [];
        foreach ($this->calls as $callId => $call) {
            $callData[$callId] = [
                'toolCallId' => $call->toolCallId,
                'toolName' => $call->toolName,
                'args' => $call->args,
                'orderIndex' => $call->orderIndex,
                'mode' => $call->mode,
                'timeoutSeconds' => $call->timeoutSeconds,
                'maxParallelism' => $call->maxParallelism,
                'toolsRef' => $call->toolsRef,
                'toolIdempotencyKey' => $call->toolIdempotencyKey,
                'assistantMessage' => $call->assistantMessage,
                'argSchema' => $call->argSchema,
            ];
        }

        // result_data stores only terminal outcomes. pendingHumanInput is a bus-only
        // nonterminal field on ToolCallResult and must never be persisted here.
        $resultData = [];
        foreach ($this->results as $toolCallId => $result) {
            $resultData[$toolCallId] = [
                'toolCallId' => $result->toolCallId,
                'orderIndex' => $result->orderIndex,
                'result' => $result->result,
                'isError' => $result->isError,
                'error' => $result->error,
            ];
        }

        return [
            'expected_order' => $this->expectedOrder,
            'call_data' => $callData,
            'pending_queue' => $this->pendingQueue,
            'in_flight' => $this->inFlight,
            'result_data' => $resultData,
            'finalized' => $this->finalized,
            'max_parallelism' => $this->maxParallelism,
            'awaiting_human_input' => $this->awaitingHumanInput,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function assertCallRowShape(string $mapKey, array $data): void
    {
        if (!\array_key_exists('toolCallId', $data) || !\is_string($data['toolCallId']) || '' === $data['toolCallId']) {
            throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s].toolCallId must be a non-empty string.', $mapKey));
        }

        if (!\array_key_exists('toolName', $data) || !\is_string($data['toolName'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s].toolName must be a string.', $mapKey));
        }

        if (!\array_key_exists('orderIndex', $data) || !\is_int($data['orderIndex'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s].orderIndex must be an integer.', $mapKey));
        }

        if (\array_key_exists('args', $data) && !\is_array($data['args'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s].args must be an array when present.', $mapKey));
        }

        if (\array_key_exists('toolIdempotencyKey', $data) && null !== $data['toolIdempotencyKey'] && !\is_string($data['toolIdempotencyKey'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s].toolIdempotencyKey must be a string or null when present.', $mapKey));
        }

        if (\array_key_exists('mode', $data) && null !== $data['mode'] && !\is_string($data['mode'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s].mode must be a string or null when present.', $mapKey));
        }

        if (\array_key_exists('toolsRef', $data) && null !== $data['toolsRef'] && !\is_string($data['toolsRef'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s].toolsRef must be a string or null when present.', $mapKey));
        }

        if (\array_key_exists('timeoutSeconds', $data) && null !== $data['timeoutSeconds'] && !\is_int($data['timeoutSeconds'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s].timeoutSeconds must be an integer or null when present.', $mapKey));
        }

        if (\array_key_exists('maxParallelism', $data) && null !== $data['maxParallelism'] && !\is_int($data['maxParallelism'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s].maxParallelism must be an integer or null when present.', $mapKey));
        }

        if (\array_key_exists('assistantMessage', $data) && null !== $data['assistantMessage'] && !\is_array($data['assistantMessage'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s].assistantMessage must be an array or null when present.', $mapKey));
        }

        if (\array_key_exists('argSchema', $data) && null !== $data['argSchema'] && !\is_array($data['argSchema'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch call_data[%s].argSchema must be an array or null when present.', $mapKey));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function assertResultRowShape(string $mapKey, array $data): void
    {
        if (!\array_key_exists('toolCallId', $data) || !\is_string($data['toolCallId']) || '' === $data['toolCallId']) {
            throw new \UnexpectedValueException(\sprintf('Tool batch result_data[%s].toolCallId must be a non-empty string.', $mapKey));
        }

        if (!\array_key_exists('orderIndex', $data) || !\is_int($data['orderIndex'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch result_data[%s].orderIndex must be an integer.', $mapKey));
        }

        if (!\array_key_exists('isError', $data) || !\is_bool($data['isError'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch result_data[%s].isError must be a boolean.', $mapKey));
        }

        if (\array_key_exists('error', $data) && null !== $data['error'] && !\is_array($data['error'])) {
            throw new \UnexpectedValueException(\sprintf('Tool batch result_data[%s].error must be an array or null when present.', $mapKey));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function reconstructCall(string $runId, int $turnNo, string $stepId, array $data): ExecuteToolCall
    {
        $toolCallId = $data['toolCallId'];

        return new ExecuteToolCall(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s|%s', $runId, $stepId, $toolCallId)),
            toolCallId: $toolCallId,
            toolName: $data['toolName'],
            args: \is_array($data['args'] ?? null) ? $data['args'] : [],
            orderIndex: $data['orderIndex'],
            toolIdempotencyKey: \array_key_exists('toolIdempotencyKey', $data) ? $data['toolIdempotencyKey'] : null,
            mode: \array_key_exists('mode', $data) ? $data['mode'] : null,
            timeoutSeconds: \array_key_exists('timeoutSeconds', $data) ? $data['timeoutSeconds'] : null,
            maxParallelism: \array_key_exists('maxParallelism', $data) ? $data['maxParallelism'] : null,
            assistantMessage: \array_key_exists('assistantMessage', $data) ? $data['assistantMessage'] : null,
            argSchema: \array_key_exists('argSchema', $data) ? $data['argSchema'] : null,
            toolsRef: \array_key_exists('toolsRef', $data) ? $data['toolsRef'] : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function reconstructResult(string $runId, int $turnNo, string $stepId, array $data): ToolCallResult
    {
        $toolCallId = $data['toolCallId'];

        return new ToolCallResult(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s|%s', $runId, $stepId, $toolCallId)),
            toolCallId: $toolCallId,
            orderIndex: $data['orderIndex'],
            result: $data['result'] ?? null,
            isError: $data['isError'],
            error: \is_array($data['error'] ?? null) ? $data['error'] : null,
        );
    }
}
