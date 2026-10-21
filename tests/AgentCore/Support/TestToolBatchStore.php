<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolBatchScheduleDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
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

    public function loadSchedule(string $runId, int $turnNo, string $stepId): ?ToolBatchScheduleDTO
    {
        $batch = $this->load($runId, $turnNo, $stepId);
        if (null === $batch) {
            return null;
        }

        return new ToolBatchScheduleDTO(
            $batch->expectedOrder,
            array_map(static fn (ExecuteToolCall $call): string => (ToolExecutionMode::tryFrom($call->mode ?? ToolExecutionMode::Sequential->value) ?? ToolExecutionMode::Sequential)->value, $batch->calls),
            $batch->pendingQueue,
            $batch->inFlight,
            array_fill_keys(array_keys($batch->results), true),
            $batch->finalized,
            $batch->maxParallelism,
            $batch->awaitingHumanInput,
        );
    }

    public function loadCall(string $runId, int $turnNo, string $stepId, string $toolCallId): ?ExecuteToolCall
    {
        return $this->load($runId, $turnNo, $stepId)?->calls[$toolCallId] ?? null;
    }

    public function loadCallReference(string $runId, int $turnNo, string $stepId, string $toolCallId): ?ToolPayloadReferenceDTO
    {
        $call = $this->loadCall($runId, $turnNo, $stepId, $toolCallId);
        if (null === $call) {
            return null;
        }
        // The fake has no files; hash its actual retained data, including answers.
        $body = serialize($call);

        return new ToolPayloadReferenceDTO('call', $runId, $turnNo, $stepId, $call->attempt(), $call->idempotencyKey(), $toolCallId, $call->orderIndex, $call->toolName, hash('sha256', $body), \strlen($body));
    }

    public function loadResult(string $runId, int $turnNo, string $stepId, string $toolCallId): ?ToolCallResult
    {
        return $this->load($runId, $turnNo, $stepId)?->results[$toolCallId] ?? null;
    }

    public function loadResults(string $runId, int $turnNo, string $stepId): array
    {
        $results = $this->load($runId, $turnNo, $stepId)?->results ?? [];
        uasort($results, static fn (ToolCallResult $left, ToolCallResult $right): int => $left->orderIndex <=> $right->orderIndex);

        return $results;
    }

    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        $batch = $this->load($runId, $turnNo, $stepId);
        if (null !== $batch && $this->isUnresolved($batch)) {
            return;
        }
        unset($this->batches[$this->key($runId, $turnNo, $stepId)], $this->applied[$this->key($runId, $turnNo, $stepId)]);
    }

    public function deleteAllForRun(string $runId): void
    {
        foreach (array_keys($this->batches) as $key) {
            if (str_starts_with($key, $runId.'|') && !$this->isUnresolved($this->batches[$key])) {
                unset($this->batches[$key], $this->applied[$key]);
            }
        }
    }

    public function hasUnresolvedExecution(string $runId): bool
    {
        foreach ($this->batches as $key => $batch) {
            if (!str_starts_with($key, $runId.'|')) {
                continue;
            }
            if ($this->isUnresolved($batch)) {
                return true;
            }
        }

        return false;
    }

    public function findUnresolvedToolCallIds(string $runId, array $toolCallIds): array
    {
        $unresolved = [];
        foreach ($this->batches as $key => $batch) {
            if (str_starts_with($key, $runId.'|') && $this->isUnresolved($batch)) {
                foreach ($toolCallIds as $id) {
                    if (isset($batch->calls[$id])) {
                        $unresolved[] = $id;
                    }
                }
            }
        }

        return array_values(array_unique($unresolved));
    }

    public function prepareChanges(array $actions, VerifiedTransitionDTO $transition): \Closure
    {
        return function () use ($actions, $transition): void {
            foreach ($actions as $action) {
                if ($action instanceof RegisterToolBatchDTO) {
                    $this->registerPrepared($action, $transition);
                } else {
                    $this->applyPrepared($action, $transition);
                }
            }
        };
    }

    private function isUnresolved(ToolBatchStateDTO $batch): bool
    {
        return !$batch->finalized || [] !== $batch->awaitingHumanInput || [] !== $batch->pendingQueue || [] !== $batch->inFlight;
    }

    private function applyPrepared(FinalizeToolBatchDTO $action, VerifiedTransitionDTO $transition): void
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
        $this->batches[$key] = $next;
        $this->applied[$key] = $transition->identity;
    }

    private function registerPrepared(RegisterToolBatchDTO $action, VerifiedTransitionDTO $transition): void
    {
        $key = $this->key($action->runId, $action->turnNo, $action->stepId);
        if (($this->applied[$key] ?? null) === $transition->identity) {
            return;
        }
        $calls = [];
        foreach ($action->effects as $call) {
            $calls[$call->toolCallId] = $call;
        }
        $existing = $this->load($action->runId, $action->turnNo, $action->stepId);
        if (null !== $existing) {
            if ($existing->expectedOrder !== $action->expectedOrder
                || $existing->pendingQueue !== $action->pendingQueue
                || $existing->inFlight !== $action->inFlight
                || $existing->maxParallelism !== $action->maxParallelism) {
                throw new \LogicException('Conflicting prepared tool batch membership.');
            }
            $this->applied[$key] = $transition->identity;

            return;
        }
        $this->batches[$key] = new ToolBatchStateDTO(
            expectedOrder: $action->expectedOrder,
            calls: $calls,
            pendingQueue: $action->pendingQueue,
            inFlight: $action->inFlight,
            results: [],
            finalized: false,
            maxParallelism: max(1, $action->maxParallelism),
            awaitingHumanInput: [],
        );
        $this->applied[$key] = $transition->identity;
    }

    private function key(string $runId, int $turnNo, string $stepId): string
    {
        return \sprintf('%s|%d|%s', $runId, $turnNo, $stepId);
    }
}
