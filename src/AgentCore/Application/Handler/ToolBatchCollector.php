<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;

/**
 * Per-run/per-turn/per-step tool batch execution coordinator.
 *
 * Registers expected tool calls from an LLM step, dispatches the initial batch,
 * and collects results as they arrive. With a durable {@see ToolBatchStoreInterface},
 * batch state survives consumer restarts and coordinates across Messenger workers.
 *
 * Cross-process coordination pipeline (LLM register/persist → parallel tool workers
 * → {@see ToolCallResult} on run_control → collector/store mutation):
 *   1. {@see LlmStepResultHandler} calls {@see registerExpectedBatch()}, which persists
 *      the batch and returns initial dispatchable {@see ExecuteToolCall} messages.
 *   2. Tool workers execute calls and dispatch {@see ToolCallResult} envelopes.
 *   3. {@see ToolCallResultHandler} calls {@see collect()}, which atomically mutates
 *      durable {@see ToolBatchStateDTO} state and may dispatch subsequent calls.
 *
 * In-process {@see ToolBatchStateDTO} shape is owned by that DTO; the optional
 * {@see ToolBatchStoreInterface} mirrors it for durability.
 */
final class ToolBatchCollector
{
    /** @var array<string, ToolBatchStateDTO> */
    private array $batches = [];

    private ?ToolBatchStoreInterface $store = null;

    public function __construct(
        private readonly int $defaultMaxParallelism = 4,
        ?ToolBatchStoreInterface $store = null,
    ) {
        $this->store = $store;
    }

    /**
     * @param list<ExecuteToolCall> $toolCalls
     *
     * @return list<ExecuteToolCall>
     */
    public function registerExpectedBatch(string $runId, int $turnNo, string $stepId, array $toolCalls): array
    {
        usort(
            $toolCalls,
            static fn (ExecuteToolCall $left, ExecuteToolCall $right): int => $left->orderIndex <=> $right->orderIndex,
        );

        $expectedOrder = [];
        $callsById = [];
        $maxParallelism = $this->defaultMaxParallelism;

        foreach ($toolCalls as $toolCall) {
            $expectedOrder[$toolCall->toolCallId] = $toolCall->orderIndex;
            $callsById[$toolCall->toolCallId] = $toolCall;
            $maxParallelism = max(1, $toolCall->maxParallelism ?? $maxParallelism);
        }

        $batch = new ToolBatchStateDTO(
            expectedOrder: $expectedOrder,
            calls: $callsById,
            pendingQueue: array_map(static fn (ExecuteToolCall $call): string => $call->toolCallId, $toolCalls),
            inFlight: [],
            results: [],
            finalized: false,
            maxParallelism: max(1, $maxParallelism),
        );

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
            function (?ToolBatchStateDTO $stored) use ($result): ToolBatchStoreMutation {
                if (null === $stored) {
                    return new ToolBatchStoreMutation(ToolBatchCollectOutcome::rejected());
                }

                $collectOutcome = $this->applyCollectToBatch($stored, $result);

                if (!$collectOutcome->accepted || $collectOutcome->duplicate) {
                    return new ToolBatchStoreMutation($collectOutcome);
                }

                return new ToolBatchStoreMutation($collectOutcome, $stored);
            },
        );

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

    private function applyCollectToBatch(ToolBatchStateDTO $batch, ToolCallResult $result): ToolBatchCollectOutcome
    {
        if (!\array_key_exists($result->toolCallId, $batch->expectedOrder)) {
            return ToolBatchCollectOutcome::rejected();
        }

        if (isset($batch->results[$result->toolCallId])) {
            return $this->outcomeForStoredResult($batch, $result);
        }

        if ($batch->finalized) {
            return $this->outcomeForStoredResult($batch, $result);
        }

        unset($batch->inFlight[$result->toolCallId]);
        $batch->results[$result->toolCallId] = $result;

        $effectsToDispatch = $this->dispatchableCalls($batch);

        if (\count($batch->results) !== \count($batch->expectedOrder)) {
            return ToolBatchCollectOutcome::acceptedPending($effectsToDispatch);
        }

        $orderedResults = array_values($batch->results);
        usort(
            $orderedResults,
            static fn (ToolCallResult $left, ToolCallResult $right): int => $left->orderIndex <=> $right->orderIndex,
        );

        $batch->finalized = true;

        return ToolBatchCollectOutcome::acceptedComplete($orderedResults, $effectsToDispatch);
    }

    private function outcomeForStoredResult(ToolBatchStateDTO $batch, ToolCallResult $result): ToolBatchCollectOutcome
    {
        $stored = $batch->results[$result->toolCallId] ?? null;
        if (!$stored instanceof ToolCallResult) {
            return ToolBatchCollectOutcome::rejected();
        }

        if (!$this->toolResultsEquivalent($stored, $result)) {
            throw new \LogicException(\sprintf('Conflicting duplicate tool result for call "%s" on run "%s".', $result->toolCallId, $result->runId()));
        }

        if (!$batch->finalized) {
            return ToolBatchCollectOutcome::duplicate();
        }

        if (\count($batch->results) !== \count($batch->expectedOrder)) {
            return ToolBatchCollectOutcome::duplicate();
        }

        $orderedResults = array_values($batch->results);
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
     * @return list<ExecuteToolCall>
     */
    private function dispatchableCalls(ToolBatchStateDTO $batch): array
    {
        $dispatch = [];

        while ([] !== $batch->pendingQueue) {
            $nextCallId = $batch->pendingQueue[0];
            if (isset($batch->results[$nextCallId])) {
                array_shift($batch->pendingQueue);

                continue;
            }

            $nextCall = $batch->calls[$nextCallId] ?? null;

            if (!$nextCall instanceof ExecuteToolCall) {
                array_shift($batch->pendingQueue);

                continue;
            }

            $mode = ToolExecutionMode::tryFrom((string) ($nextCall->mode ?? ToolExecutionMode::Sequential->value))
                ?? ToolExecutionMode::Sequential;

            if (ToolExecutionMode::Sequential === $mode || ToolExecutionMode::Interrupt === $mode) {
                if ([] !== $batch->inFlight) {
                    break;
                }

                array_shift($batch->pendingQueue);
                $batch->inFlight[$nextCallId] = true;
                $dispatch[] = $nextCall;

                break;
            }

            if (\count($batch->inFlight) >= $batch->maxParallelism) {
                break;
            }

            array_shift($batch->pendingQueue);
            $batch->inFlight[$nextCallId] = true;
            $dispatch[] = $nextCall;
        }

        return $dispatch;
    }

    private function loadBatch(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO
    {
        $batchKey = $this->batchKey($runId, $turnNo, $stepId);

        if (isset($this->batches[$batchKey])) {
            return $this->batches[$batchKey];
        }

        $stored = $this->store?->load($runId, $turnNo, $stepId);
        if (null !== $stored) {
            $this->batches[$batchKey] = $stored;

            return $stored;
        }

        return null;
    }

    private function saveBatch(string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $batch): void
    {
        if (null !== $this->store) {
            // Store-first: durable write must succeed before any in-process view changes
            // so Messenger retry reloads the last persisted snapshot, not a dirty cache.
            $this->store->save($runId, $turnNo, $stepId, $batch);

            return;
        }

        $this->batches[$this->batchKey($runId, $turnNo, $stepId)] = $batch;
    }

    private function batchKey(string $runId, int $turnNo, string $stepId): string
    {
        return \sprintf('%s|%d|%s', $runId, $turnNo, $stepId);
    }
}
