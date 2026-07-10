<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;

/**
 * Per-run/per-turn/per-step tool batch execution coordinator.
 *
 * Registers expected tool calls from an LLM step, dispatches the initial
 * batch, and collects results as they arrive. Uses an optional durable
 * ToolBatchStoreInterface so batch state survives consumer process restarts
 * and coordinates across llm/tool Messenger consumers.
 *
 * The dispatch pipeline (llm consumer → tool transport → tool consumer):
 *   1. LlmStepResultHandler calls registerExpectedBatch() which persists
 *      the batch and returns the initial dispatchable calls.
 *   2. Each tool consumer executes a tool call and dispatches ToolCallResult.
 *   3. ToolCallResultHandler calls collect() which loads batch state from
 *      the durable store (allowing cross-process coordination), processes
 *      the result, dispatches subsequent calls if applicable.
 *
 * Internal batch state shape (mirrored in ToolBatchStoreInterface):
 *   expected_order  : array<string, int>        — toolCallId => orderIndex
 *   calls           : array<string, ExecuteToolCall>
 *   pending_queue   : list<string>              — ordered toolCallIds to dispatch
 *   in_flight       : array<string, true>
 *   results         : array<string, ToolCallResult>
 *   finalized       : bool
 *   max_parallelism : int
 */
final class ToolBatchCollector
{
    /**
     * @var array<string, array{
     *   expected_order: array<string, int>,
     *   calls: array<string, ExecuteToolCall>,
     *   pending_queue: list<string>,
     *   in_flight: array<string, true>,
     *   results: array<string, ToolCallResult>,
     *   finalized: bool,
     *   max_parallelism: int
     * }>
     */
    private array $batches = [];

    private ?ToolBatchStoreInterface $store = null;

    public function __construct(
        private readonly int $defaultMaxParallelism = 4,
        ?ToolBatchStoreInterface $store = null,
    ) {
        $this->store = $store;
    }

    /**
     * Registers a new batch of tool calls identified by run, turn, and step IDs.
     *
     * @param list<ExecuteToolCall> $toolCalls
     *
     * @return list<ExecuteToolCall> The initial dispatchable subset of calls
     */
    public function registerExpectedBatch(string $runId, int $turnNo, string $stepId, array $toolCalls): array
    {
        $expectedOrder = [];
        $callsById = [];

        usort(
            $toolCalls,
            static fn (ExecuteToolCall $left, ExecuteToolCall $right): int => $left->orderIndex <=> $right->orderIndex,
        );

        $maxParallelism = $this->defaultMaxParallelism;

        foreach ($toolCalls as $toolCall) {
            $expectedOrder[$toolCall->toolCallId] = $toolCall->orderIndex;
            $callsById[$toolCall->toolCallId] = $toolCall;

            $maxParallelism = max(1, $toolCall->maxParallelism ?? $maxParallelism);
        }

        $batch = [
            'expected_order' => $expectedOrder,
            'calls' => $callsById,
            'pending_queue' => array_map(static fn (ExecuteToolCall $call): string => $call->toolCallId, $toolCalls),
            'in_flight' => [],
            'results' => [],
            'finalized' => false,
            'max_parallelism' => max(1, $maxParallelism),
        ];

        $initialDispatch = $this->dispatchableCalls($batch);

        $this->saveBatch($runId, $turnNo, $stepId, $batch);

        return $initialDispatch;
    }

    public function collect(ToolCallResult $result): ToolBatchCollectOutcome
    {
        if (null !== $this->store) {
            return $this->collectWithDurableStore($result);
        }

        return $this->collectInMemory($result);
    }

    private function collectWithDurableStore(ToolCallResult $result): ToolBatchCollectOutcome
    {
        $runId = $result->runId();
        $turnNo = $result->turnNo();
        $stepId = $result->stepId();

        /** @var ToolBatchCollectOutcome $outcome */
        $outcome = $this->store->mutate(
            $runId,
            $turnNo,
            $stepId,
            function (?array $stored) use ($result, $runId, $turnNo, $stepId): ToolBatchStoreMutation {
                if (null === $stored) {
                    return new ToolBatchStoreMutation(ToolBatchCollectOutcome::rejected());
                }

                $batch = $this->reconstructBatch($runId, $turnNo, $stepId, $stored);
                $collectOutcome = $this->applyCollectToBatch($batch, $result);

                if (!$collectOutcome->accepted) {
                    return new ToolBatchStoreMutation($collectOutcome);
                }

                if ($collectOutcome->duplicate) {
                    return new ToolBatchStoreMutation($collectOutcome);
                }

                return new ToolBatchStoreMutation(
                    $collectOutcome,
                    $this->serializeBatch($batch),
                );
            },
        );

        $refreshed = $this->loadBatch($runId, $turnNo, $stepId);
        if (null !== $refreshed) {
            $this->batches[$this->batchKey($runId, $turnNo, $stepId)] = $refreshed;
        } else {
            unset($this->batches[$this->batchKey($runId, $turnNo, $stepId)]);
        }

        return $outcome;
    }

    private function collectInMemory(ToolCallResult $result): ToolBatchCollectOutcome
    {
        $batch = $this->loadBatch($result->runId(), $result->turnNo(), $result->stepId());

        if (null === $batch) {
            return ToolBatchCollectOutcome::rejected();
        }

        $outcome = $this->applyCollectToBatch($batch, $result);

        if ($outcome->accepted && !$outcome->duplicate) {
            $this->saveBatch($result->runId(), $result->turnNo(), $result->stepId(), $batch);
        }

        return $outcome;
    }

    /**
     * @param array{
     *   expected_order: array<string, int>,
     *   calls: array<string, ExecuteToolCall>,
     *   pending_queue: list<string>,
     *   in_flight: array<string, true>,
     *   results: array<string, ToolCallResult>,
     *   finalized: bool,
     *   max_parallelism: int
     * } $batch
     */
    private function applyCollectToBatch(array &$batch, ToolCallResult $result): ToolBatchCollectOutcome
    {
        if (!\array_key_exists($result->toolCallId, $batch['expected_order'])) {
            return ToolBatchCollectOutcome::rejected();
        }

        if (isset($batch['results'][$result->toolCallId])) {
            return $this->outcomeForStoredResult($batch, $result);
        }

        if (true === ($batch['finalized'] ?? false)) {
            return $this->outcomeForStoredResult($batch, $result);
        }

        unset($batch['in_flight'][$result->toolCallId]);
        $batch['results'][$result->toolCallId] = $result;

        $effectsToDispatch = $this->dispatchableCalls($batch);

        if (\count($batch['results']) !== \count($batch['expected_order'])) {
            return ToolBatchCollectOutcome::acceptedPending($effectsToDispatch);
        }

        $orderedResults = array_values($batch['results']);
        usort(
            $orderedResults,
            static fn (ToolCallResult $left, ToolCallResult $right): int => $left->orderIndex <=> $right->orderIndex,
        );

        $batch['finalized'] = true;

        return ToolBatchCollectOutcome::acceptedComplete($orderedResults, $effectsToDispatch);
    }

    /**
     * @param array{
     *   expected_order: array<string, int>,
     *   calls: array<string, ExecuteToolCall>,
     *   pending_queue: list<string>,
     *   in_flight: array<string, true>,
     *   results: array<string, ToolCallResult>,
     *   finalized: bool,
     *   max_parallelism: int
     * } $batch
     */
    private function outcomeForStoredResult(array $batch, ToolCallResult $result): ToolBatchCollectOutcome
    {
        $stored = $batch['results'][$result->toolCallId] ?? null;
        if (!$stored instanceof ToolCallResult) {
            return ToolBatchCollectOutcome::rejected();
        }

        if (!$this->toolResultsEquivalent($stored, $result)) {
            throw new \LogicException(\sprintf('Conflicting duplicate tool result for call "%s" on run "%s".', $result->toolCallId, $result->runId()));
        }

        if (true !== ($batch['finalized'] ?? false)) {
            return ToolBatchCollectOutcome::duplicate();
        }

        if (\count($batch['results']) !== \count($batch['expected_order'])) {
            return ToolBatchCollectOutcome::duplicate();
        }

        $orderedResults = array_values($batch['results']);
        usort(
            $orderedResults,
            static fn (ToolCallResult $left, ToolCallResult $right): int => $left->orderIndex <=> $right->orderIndex,
        );

        return ToolBatchCollectOutcome::acceptedComplete($orderedResults, []);
    }

    private function toolResultsEquivalent(ToolCallResult $left, ToolCallResult $right): bool
    {
        return $left->toolCallId === $right->toolCallId
            && $left->orderIndex === $right->orderIndex
            && $left->isError === $right->isError
            && $left->result === $right->result
            && $left->error === $right->error;
    }

    /**
     * Identifies and extracts tool calls from a batch that are ready for dispatch.
     *
     * @param array{
     *   expected_order: array<string, int>,
     *   calls: array<string, ExecuteToolCall>,
     *   pending_queue: list<string>,
     *   in_flight: array<string, true>,
     *   results: array<string, ToolCallResult>,
     *   finalized: bool,
     *   max_parallelism: int
     * } $batch
     *
     * @return list<ExecuteToolCall>
     */
    private function dispatchableCalls(array &$batch): array
    {
        $dispatch = [];

        while ([] !== $batch['pending_queue']) {
            $nextCallId = $batch['pending_queue'][0];
            if (isset($batch['results'][$nextCallId])) {
                array_shift($batch['pending_queue']);

                continue;
            }

            $nextCall = $batch['calls'][$nextCallId] ?? null;

            if (!$nextCall instanceof ExecuteToolCall) {
                array_shift($batch['pending_queue']);

                continue;
            }

            $mode = ToolExecutionMode::tryFrom((string) ($nextCall->mode ?? ToolExecutionMode::Sequential->value))
                ?? ToolExecutionMode::Sequential;

            if (ToolExecutionMode::Sequential === $mode || ToolExecutionMode::Interrupt === $mode) {
                if ([] !== $batch['in_flight']) {
                    break;
                }

                array_shift($batch['pending_queue']);
                $batch['in_flight'][$nextCallId] = true;
                $dispatch[] = $nextCall;

                break;
            }

            if (\count($batch['in_flight']) >= $batch['max_parallelism']) {
                break;
            }

            array_shift($batch['pending_queue']);
            $batch['in_flight'][$nextCallId] = true;
            $dispatch[] = $nextCall;
        }

        return $dispatch;
    }

    // ---------------------------------------------------------------
    // Durable store integration
    // ---------------------------------------------------------------

    /**
     * Load batch state, checking in-memory cache first then durable store.
     *
     * @return array<string, mixed>|null The batch state array
     */
    private function loadBatch(string $runId, int $turnNo, string $stepId): ?array
    {
        $batchKey = $this->batchKey($runId, $turnNo, $stepId);

        if (isset($this->batches[$batchKey])) {
            return $this->batches[$batchKey];
        }

        $stored = $this->store?->load($runId, $turnNo, $stepId);
        if (null !== $stored) {
            $reconstructed = $this->reconstructBatch($runId, $turnNo, $stepId, $stored);
            $this->batches[$batchKey] = $reconstructed;

            return $reconstructed;
        }

        return null;
    }

    /**
     * Save batch state to durable store first, then update in-memory cache.
     *
     * If the durable write fails, the current Messenger message must be retried
     * against the last persisted state rather than a dirty in-process cache.
     *
     * @param array<string, mixed> $batch
     */
    private function saveBatch(string $runId, int $turnNo, string $stepId, array $batch): void
    {
        $batchKey = $this->batchKey($runId, $turnNo, $stepId);
        $this->store?->save($runId, $turnNo, $stepId, $this->serializeBatch($batch));
        $this->batches[$batchKey] = $batch;
    }

    /**
     * @param array<string, mixed> $batch
     *
     * @return array{
     *   expected_order: array<string, int>,
     *   call_data: array<string, array<string, mixed>>,
     *   pending_queue: list<string>,
     *   in_flight: array<string, true>,
     *   result_data: array<string, array<string, mixed>>,
     *   finalized: bool,
     *   max_parallelism: int
     * }
     */
    private function serializeBatch(array $batch): array
    {
        $callData = [];
        foreach ($batch['calls'] as $callId => $call) {
            \assert($call instanceof ExecuteToolCall);
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
        foreach ($batch['results'] as $toolCallId => $result) {
            \assert($result instanceof ToolCallResult);
            $resultData[$toolCallId] = [
                'toolCallId' => $result->toolCallId,
                'orderIndex' => $result->orderIndex,
                'result' => $result->result,
                'isError' => $result->isError,
                'error' => $result->error,
            ];
        }

        return [
            'expected_order' => $batch['expected_order'],
            'call_data' => $callData,
            'pending_queue' => $batch['pending_queue'],
            'in_flight' => $batch['in_flight'],
            'result_data' => $resultData,
            'finalized' => $batch['finalized'],
            'max_parallelism' => $batch['max_parallelism'],
        ];
    }

    /**
     * Reconstruct a full batch state array from stored serialized data.
     *
     * @param array<string, mixed> $stored
     *
     * @return array{
     *   expected_order: array<string, int>,
     *   calls: array<string, ExecuteToolCall>,
     *   pending_queue: list<string>,
     *   in_flight: array<string, true>,
     *   results: array<string, ToolCallResult>,
     *   finalized: bool,
     *   max_parallelism: int
     * }
     */
    private function reconstructBatch(string $runId, int $turnNo, string $stepId, array $stored): array
    {
        $calls = [];
        foreach ($stored['call_data'] as $data) {
            $calls[$data['toolCallId']] = $this->reconstructCall($runId, $turnNo, $stepId, $data);
        }

        $results = [];
        foreach ($stored['result_data'] as $data) {
            $results[$data['toolCallId']] = new ToolCallResult(
                runId: $runId,
                turnNo: $turnNo,
                stepId: $stepId,
                attempt: 1,
                idempotencyKey: hash('sha256', \sprintf('%s|%s|%s', $runId, $stepId, $data['toolCallId'])),
                toolCallId: $data['toolCallId'],
                orderIndex: $data['orderIndex'],
                result: $data['result'],
                isError: $data['isError'],
                error: $data['error'],
            );
        }

        return [
            'expected_order' => $stored['expected_order'],
            'calls' => $calls,
            'pending_queue' => $stored['pending_queue'],
            'in_flight' => $stored['in_flight'],
            'results' => $results,
            'finalized' => $stored['finalized'],
            'max_parallelism' => $stored['max_parallelism'],
        ];
    }

    /**
     * @param array{
     *   toolCallId: string,
     *   toolName: string,
     *   args: array<string, mixed>,
     *   orderIndex: int,
     *   mode: string|null,
     *   timeoutSeconds: int|null,
     *   maxParallelism: int|null,
     *   toolsRef: string|null,
     *   toolIdempotencyKey: string|null,
     *   assistantMessage: array<string, mixed>|null,
     *   argSchema: array<string, mixed>|null,
     * } $data
     */
    private function reconstructCall(string $runId, int $turnNo, string $stepId, array $data): ExecuteToolCall
    {
        return new ExecuteToolCall(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s|%s', $runId, $stepId, $data['toolCallId'])),
            toolCallId: $data['toolCallId'],
            toolName: $data['toolName'],
            args: $data['args'],
            orderIndex: $data['orderIndex'],
            toolIdempotencyKey: $data['toolIdempotencyKey'] ?? null,
            mode: $data['mode'] ?? null,
            timeoutSeconds: $data['timeoutSeconds'] ?? null,
            maxParallelism: $data['maxParallelism'] ?? null,
            assistantMessage: $data['assistantMessage'] ?? null,
            argSchema: $data['argSchema'] ?? null,
            toolsRef: $data['toolsRef'] ?? null,
        );
    }

    private function batchKey(string $runId, int $turnNo, string $stepId): string
    {
        return \sprintf('%s|%d|%s', $runId, $turnNo, $stepId);
    }
}
