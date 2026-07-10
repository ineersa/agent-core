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
     */
    public function __construct(
        public array $expectedOrder,
        public array $calls,
        public array $pendingQueue,
        public array $inFlight,
        public array $results,
        public bool $finalized,
        public int $maxParallelism,
    ) {
    }

    public static function empty(int $maxParallelism = 2): self
    {
        return new self([], [], [], [], [], false, max(1, $maxParallelism));
    }

    /**
     * @param array<string, mixed> $data Persisted batch_state blob from snapshot envelope
     */
    public static function fromPersistedArray(array $data, string $runId, int $turnNo, string $stepId): self
    {
        $calls = [];
        foreach ($data['call_data'] ?? [] as $callId => $callRow) {
            if (!\is_array($callRow)) {
                continue;
            }
            $calls[(string) $callId] = self::reconstructCall($runId, $turnNo, $stepId, $callRow);
        }

        $results = [];
        foreach ($data['result_data'] ?? [] as $callId => $resultRow) {
            if (!\is_array($resultRow)) {
                continue;
            }
            $results[(string) $callId] = self::reconstructResult($runId, $turnNo, $stepId, $resultRow);
        }

        $inFlight = [];
        foreach ($data['in_flight'] ?? [] as $callId => $flag) {
            if (true === $flag) {
                $inFlight[(string) $callId] = true;
            }
        }

        return new self(
            expectedOrder: \is_array($data['expected_order'] ?? null) ? $data['expected_order'] : [],
            calls: $calls,
            pendingQueue: \is_array($data['pending_queue'] ?? null) ? array_values($data['pending_queue']) : [],
            inFlight: $inFlight,
            results: $results,
            finalized: (bool) ($data['finalized'] ?? false),
            maxParallelism: max(1, (int) ($data['max_parallelism'] ?? 1)),
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
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function reconstructCall(string $runId, int $turnNo, string $stepId, array $data): ExecuteToolCall
    {
        $toolCallId = (string) ($data['toolCallId'] ?? '');

        return new ExecuteToolCall(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s|%s', $runId, $stepId, $toolCallId)),
            toolCallId: $toolCallId,
            toolName: (string) ($data['toolName'] ?? ''),
            args: \is_array($data['args'] ?? null) ? $data['args'] : [],
            orderIndex: (int) ($data['orderIndex'] ?? 0),
            toolIdempotencyKey: isset($data['toolIdempotencyKey']) ? (string) $data['toolIdempotencyKey'] : null,
            mode: isset($data['mode']) ? (string) $data['mode'] : null,
            timeoutSeconds: isset($data['timeoutSeconds']) ? (int) $data['timeoutSeconds'] : null,
            maxParallelism: isset($data['maxParallelism']) ? (int) $data['maxParallelism'] : null,
            assistantMessage: \is_array($data['assistantMessage'] ?? null) ? $data['assistantMessage'] : null,
            argSchema: \is_array($data['argSchema'] ?? null) ? $data['argSchema'] : null,
            toolsRef: isset($data['toolsRef']) ? (string) $data['toolsRef'] : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function reconstructResult(string $runId, int $turnNo, string $stepId, array $data): ToolCallResult
    {
        $toolCallId = (string) ($data['toolCallId'] ?? '');

        return new ToolCallResult(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s|%s', $runId, $stepId, $toolCallId)),
            toolCallId: $toolCallId,
            orderIndex: (int) ($data['orderIndex'] ?? 0),
            result: $data['result'] ?? null,
            isError: (bool) ($data['isError'] ?? false),
            error: \is_array($data['error'] ?? null) ? $data['error'] : null,
        );
    }
}
