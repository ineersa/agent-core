<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Message\ToolCallResult;

final class ToolBatchCollector
{
    /**
     * @var array<string, array{
     *   expected_order: array<string, int>,
     *   results: array<string, ToolCallResult>,
     *   finalized: bool
     * }>
     */
    private array $batches = [];

    /**
     * @param list<array{id: string, order_index: int}> $toolCalls
     */
    public function registerExpectedBatch(string $runId, int $turnNo, string $stepId, array $toolCalls): void
    {
        $expectedOrder = [];

        foreach ($toolCalls as $toolCall) {
            $expectedOrder[$toolCall['id']] = $toolCall['order_index'];
        }

        $this->batches[$this->batchKey($runId, $turnNo, $stepId)] = [
            'expected_order' => $expectedOrder,
            'results' => [],
            'finalized' => false,
        ];
    }

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

        $batch['results'][$result->toolCallId] = $result;

        if (\count($batch['results']) !== \count($batch['expected_order'])) {
            $this->batches[$batchKey] = $batch;

            return ToolBatchCollectOutcome::acceptedPending();
        }

        $orderedResults = array_values($batch['results']);
        usort(
            $orderedResults,
            static fn (ToolCallResult $left, ToolCallResult $right): int => $left->orderIndex <=> $right->orderIndex,
        );

        $batch['finalized'] = true;
        $this->batches[$batchKey] = $batch;

        return ToolBatchCollectOutcome::acceptedComplete($orderedResults);
    }

    private function batchKey(string $runId, int $turnNo, string $stepId): string
    {
        return \sprintf('%s|%d|%s', $runId, $turnNo, $stepId);
    }
}
