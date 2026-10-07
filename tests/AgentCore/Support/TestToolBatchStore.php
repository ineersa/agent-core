<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;

/** In-memory scheduling store for collector/finalizer unit proofs. */
final class TestToolBatchStore implements ToolBatchStoreInterface
{
    /** @var array<string, ToolBatchStateDTO> */
    private array $batches = [];

    /** @var array<string, string> */
    private array $applied = [];

    public function load(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO
    {
        return $this->batches[$this->key($runId, $turnNo, $stepId)] ?? null;
    }

    public function save(string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $batchState): void
    {
        $this->batches[$this->key($runId, $turnNo, $stepId)] = $batchState;
    }

    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        unset($this->batches[$this->key($runId, $turnNo, $stepId)], $this->applied[$this->key($runId, $turnNo, $stepId)]);
    }

    public function deleteAllForRun(string $runId): void
    {
        foreach (array_keys($this->batches) as $key) {
            if (str_starts_with($key, $runId.'|')) {
                unset($this->batches[$key], $this->applied[$key]);
            }
        }
    }

    public function hasUnresolvedExecution(string $runId, ?string $toolCallId = null): bool
    {
        foreach ($this->batches as $key => $batch) {
            if (!str_starts_with($key, $runId.'|')) {
                continue;
            }
            if (null !== $toolCallId && !isset($batch->calls[$toolCallId])) {
                continue;
            }
            if (!$batch->finalized || [] !== $batch->awaitingHumanInput || [] !== $batch->pendingQueue || [] !== $batch->inFlight) {
                return true;
            }
        }

        return false;
    }

    public function reclaimDisposedPayloads(string $runId, string $afterFilename): string
    {
        return '';
    }

    public function mutate(string $runId, int $turnNo, string $stepId, callable $callback): mixed
    {
        $outcome = $callback($this->load($runId, $turnNo, $stepId));
        if (!$outcome instanceof ToolBatchStoreMutation) {
            throw new \LogicException('Tool batch store mutate callback must return ToolBatchStoreMutation.');
        }
        if (null !== $outcome->nextState) {
            $this->save($runId, $turnNo, $stepId, $outcome->nextState);
        }

        return $outcome->returnValue;
    }

    public function applyPrepared(FinalizeToolBatchDTO $action, VerifiedTransitionDTO $transition): void
    {
        $key = $this->key($action->runId, $action->turnNo, $action->stepId);
        if (($this->applied[$key] ?? null) === $transition->identity) {
            return;
        }
        $batch = $this->load($action->runId, $action->turnNo, $action->stepId);
        if (null === $batch) {
            throw new \RuntimeException('Prepared batch evidence is missing.');
        }
        $next = clone $batch;
        $next->pendingQueue = $action->pendingQueue;
        $next->inFlight = $action->inFlight;
        $next->awaitingHumanInput = $action->awaitingHumanInput;
        $next->finalized = $action->finalized;
        if (null !== $action->result) {
            $next->results[$action->result->toolCallId] = $action->result;
        }
        if (null !== $action->revisedCallId) {
            $next->calls[$action->revisedCallId] = null === $action->answer
                ? $next->calls[$action->revisedCallId]->withHumanInputAnswer(null)
                : $next->calls[$action->revisedCallId]->withAuthorizedHumanAnswer($action->answer);
        }
        $this->save($action->runId, $action->turnNo, $action->stepId, $next);
        $this->applied[$key] = $transition->identity;
    }

    public function registerPrepared(RegisterToolBatchDTO $action, VerifiedTransitionDTO $transition): void
    {
        $key = $this->key($action->runId, $action->turnNo, $action->stepId);
        if (($this->applied[$key] ?? null) === $transition->identity) {
            return;
        }
        $effects = $action->effects;
        usort($effects, static fn (ExecuteToolCall $left, ExecuteToolCall $right): int => $left->orderIndex <=> $right->orderIndex);
        $expectedOrder = [];
        $calls = [];
        $maxParallelism = 1;
        foreach ($effects as $call) {
            $expectedOrder[$call->toolCallId] = $call->orderIndex;
            $calls[$call->toolCallId] = $call;
            $maxParallelism = max(1, $call->maxParallelism ?? $maxParallelism);
        }
        $existing = $this->load($action->runId, $action->turnNo, $action->stepId);
        if (null !== $existing) {
            $this->applied[$key] = $transition->identity;

            return;
        }
        $batch = new ToolBatchStateDTO(
            expectedOrder: $expectedOrder,
            calls: $calls,
            pendingQueue: array_map(static fn (ExecuteToolCall $call): string => $call->toolCallId, $effects),
            inFlight: [],
            results: [],
            finalized: false,
            maxParallelism: max(1, $maxParallelism),
            awaitingHumanInput: [],
        );
        while ([] !== $batch->pendingQueue) {
            $nextCallId = $batch->pendingQueue[0];
            $nextCall = $batch->calls[$nextCallId] ?? null;
            if (!$nextCall instanceof ExecuteToolCall) {
                array_shift($batch->pendingQueue);
                continue;
            }
            $mode = ToolExecutionMode::tryFrom((string) ($nextCall->mode ?? ToolExecutionMode::Sequential->value)) ?? ToolExecutionMode::Sequential;
            if (ToolExecutionMode::Sequential === $mode || ToolExecutionMode::Interrupt === $mode) {
                if ([] !== $batch->inFlight) {
                    break;
                }
                array_shift($batch->pendingQueue);
                $batch->inFlight[$nextCallId] = true;
                break;
            }
            if (\count($batch->inFlight) >= $batch->maxParallelism) {
                break;
            }
            array_shift($batch->pendingQueue);
            $batch->inFlight[$nextCallId] = true;
        }
        $this->save($action->runId, $action->turnNo, $action->stepId, $batch);
        $this->applied[$key] = $transition->identity;
    }

    public function admittedCalls(string $runId, int $turnNo, string $stepId): array
    {
        $batch = $this->load($runId, $turnNo, $stepId);
        if (null === $batch) {
            return [];
        }
        $admitted = [];
        foreach (array_keys($batch->inFlight) as $toolCallId) {
            $call = $batch->calls[$toolCallId] ?? null;
            if ($call instanceof ExecuteToolCall && !isset($batch->results[$toolCallId])) {
                $admitted[] = $call;
            }
        }

        return $admitted;
    }

    private function key(string $runId, int $turnNo, string $stepId): string
    {
        return \sprintf('%s|%d|%s', $runId, $turnNo, $stepId);
    }
}
