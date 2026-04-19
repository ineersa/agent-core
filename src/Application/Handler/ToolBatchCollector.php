<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;

/**
 * The ToolBatchCollector aggregates individual tool calls into batches based on run, turn, and step identifiers, enforcing a configurable maximum parallelism limit. It tracks expected batches and collects results to determine when a batch is complete and ready for dispatch.
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

    /**
     * Initializes the collector with a default maximum parallelism limit.
     */
    public function __construct(
        private readonly int $defaultMaxParallelism = 4,
    ) {
    }

    /**
     * Registers a new batch of tool calls identified by run, turn, and step IDs.
     *
     * @param list<ExecuteToolCall> $toolCalls
     *
     * @return list<ExecuteToolCall>
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

        $this->batches[$this->batchKey($runId, $turnNo, $stepId)] = $batch;

        return $initialDispatch;
    }

    /**
     * Collects a tool call result and returns the batch collection outcome.
     */
    public function collect(ToolCallResult $result): ToolBatchCollectOutcome
    {
        $batchKey = $this->batchKey($result->runId(), $result->turnNo(), $result->stepId());
        $batch = $this->batches[$batchKey] ?? null;

        if (null === $batch) {
            return ToolBatchCollectOutcome::rejected();
        }

        if ($batch['finalized']) {
            return ToolBatchCollectOutcome::duplicate();
        }

        if (!\array_key_exists($result->toolCallId, $batch['expected_order'])) {
            return ToolBatchCollectOutcome::rejected();
        }

        if (isset($batch['results'][$result->toolCallId])) {
            return ToolBatchCollectOutcome::duplicate();
        }

        unset($batch['in_flight'][$result->toolCallId]);
        $batch['results'][$result->toolCallId] = $result;

        $effectsToDispatch = $this->dispatchableCalls($batch);

        if (\count($batch['results']) !== \count($batch['expected_order'])) {
            $this->batches[$batchKey] = $batch;

            return ToolBatchCollectOutcome::acceptedPending($effectsToDispatch);
        }

        $orderedResults = array_values($batch['results']);
        usort(
            $orderedResults,
            static fn (ToolCallResult $left, ToolCallResult $right): int => $left->orderIndex <=> $right->orderIndex,
        );

        $batch['finalized'] = true;
        $this->batches[$batchKey] = $batch;

        return ToolBatchCollectOutcome::acceptedComplete($orderedResults, $effectsToDispatch);
    }

    /**
     * Identifies and extracts tool calls from a batch that are ready for dispatch.
     *
     * @param array{
     * expected_order: array<string, int>,
     * calls: array<string, ExecuteToolCall>,
     * pending_queue: list<string>,
     * in_flight: array<string, true>,
     * results: array<string, ToolCallResult>,
     * finalized: bool,
     * max_parallelism: int
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

            $mode = ToolExecutionMode::tryFrom((string) ($nextCall->mode ?? ToolExecutionMode::Sequential->value)) ?? ToolExecutionMode::Sequential;

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

    /**
     * Generates a unique composite key from run, turn, and step identifiers.
     */
    private function batchKey(string $runId, int $turnNo, string $stepId): string
    {
        return \sprintf('%s|%d|%s', $runId, $turnNo, $stepId);
    }
}
