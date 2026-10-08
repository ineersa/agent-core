<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;

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
        $this->batches[$key] = $next;
        $this->applied[$key] = $transition->identity;
    }

    public function registerPrepared(RegisterToolBatchDTO $action, VerifiedTransitionDTO $transition): void
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

    public function admittedCalls(string $runId, int $turnNo, string $stepId): array
    {
        $batch = $this->load($runId, $turnNo, $stepId);
        if (null === $batch) {
            return [];
        }
        $admitted = [];
        foreach (array_keys($batch->inFlight) as $toolCallId) {
            $call = $batch->calls[$toolCallId] ?? null;
            if ($call instanceof ExecuteToolCall && !isset($batch->results[$toolCallId]) && $this->isAdmissiblePermission($call)) {
                $admitted[] = $call;
            }
        }

        return $admitted;
    }

    public function admittedCall(string $runId, int $turnNo, string $stepId, string $toolCallId): ?ExecuteToolCall
    {
        $batch = $this->load($runId, $turnNo, $stepId);
        $call = $batch?->calls[$toolCallId] ?? null;

        return $call instanceof ExecuteToolCall ? $call : null;
    }

    public function isAdmissiblePermission(ExecuteToolCall $call): bool
    {
        return true;
    }

    private function key(string $runId, int $turnNo, string $stepId): string
    {
        return \sprintf('%s|%d|%s', $runId, $turnNo, $stepId);
    }
}
