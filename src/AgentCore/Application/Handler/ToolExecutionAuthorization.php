<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/** Execution authorization belongs to the existing durable batch, never the delivery. */
final readonly class ToolExecutionAuthorization implements \Ineersa\AgentCore\Contract\Tool\ToolExecutionAuthorizationInterface
{
    private string $instanceToken;
    private LockInterface $workerLock;

    public function __construct(private ToolBatchStoreInterface $store, private SerializerInterface $serializer, private \Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface $deferredRepository, #[Autowire(service: 'hatfield.controller.session_owner.lock_factory')] private LockFactory $claimLockFactory, private \Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface $executionOperations, private RunLockManager $runLocks)
    {
        $this->instanceToken = bin2hex(random_bytes(32));
        $this->workerLock = $claimLockFactory->createLock('tool-execution-worker.'.$this->instanceToken, ttl: null);
    }

    public function unknownExecutionsForRepair(string $runId): array
    {
        return $this->store->unknownExecutionsForRepair($runId);
    }

    public function assertUnknownRepairable(\Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown $notice): void
    {
        $this->withUnknownExclusion($notice, static fn () => null);
    }

    public function retireUnknownExecution(\Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO $action, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
    {
        $action->verifyTransition($transition);
        $notice = $action->notice;
        if (!$notice instanceof \Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown) {
            throw new \RuntimeException('Tool retirement requires a batch execution receipt.');
        }
        $this->runLocks->synchronized($notice->runId(), function () use ($notice, $transition): void {
            $receipt = $this->store->load($notice->runId(), $notice->turnNo(), $notice->stepId())?->executionAuthorizations[$notice->authorizationId] ?? null;
            if (null !== $receipt && 'Stale' === $receipt['state'] && ($receipt['unknown_repair_transition'] ?? null) === $transition->identity && $receipt['claim'] === $notice->claimToken
                && ($receipt['invocation'] ?? null) === ['attempt' => $notice->attempt(), 'key' => $notice->idempotencyKey(), 'call_id' => $notice->toolCallId]) {
                return;
            }
            $this->withUnknownExclusion($notice, function () use ($notice, $transition): void {
                $this->store->mutate($notice->runId(), $notice->turnNo(), $notice->stepId(), static function (?ToolBatchStateDTO $batch) use ($notice, $transition): ToolBatchStoreMutation {
                    $receipt = $batch?->executionAuthorizations[$notice->authorizationId] ?? null;
                    if (null === $batch || null === $receipt || 'OutcomeUnknown' !== $receipt['state'] || $receipt['claim'] !== $notice->claimToken) {
                        throw new \RuntimeException('Unknown tool retirement lost its precise receipt.');
                    }
                    $batch->executionAuthorizations[$notice->authorizationId] = [...$receipt, 'state' => 'Stale', 'unknown_repair_transition' => $transition->identity];

                    return new ToolBatchStoreMutation(null, $batch);
                });
            });
        });
    }

    public function arm(ExecuteToolCall $call): void
    {
        $this->store->mutate($call->runId(), $call->turnNo(), $call->stepId(), function (?ToolBatchStateDTO $batch) use ($call): ToolBatchStoreMutation {
            $this->requireCall($batch, $call);
            $key = $this->identity($call);
            if (!isset($batch->executionAuthorizations[$key])) {
                $batch->executionAuthorizations[$key] = ['state' => 'Armed', 'claim' => null, 'invocation' => ['attempt' => $call->attempt(), 'key' => $call->idempotencyKey(), 'call_id' => $call->toolCallId]];
            }

            return new ToolBatchStoreMutation(null, $batch);
        });
    }

    /** A duplicate Running delivery returns null. A durable result is returned unchanged. */
    public function claim(ExecuteToolCall $call): string|ToolCallResult|null
    {
        // Both execution authorities share owner serialization for unknown
        // decisions and claims; a check before an unrelated claim can race.
        return $this->runLocks->synchronized($call->runId(), fn (): string|ToolCallResult|null => $this->claimUnderRunLock($call));
    }

    /** @return iterable<ExecuteToolCall|ToolCallResult|\Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown> */
    public function pendingDeliveries(string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $batch): iterable
    {
        foreach ($batch->executionAuthorizations as $key => $authorization) {
            if ('Armed' === $authorization['state']) {
                foreach ($batch->calls as $call) {
                    if ($this->identity($call) === $key) {
                        yield $call;
                        break;
                    }
                }
            } elseif ('ResultReady' === $authorization['state']) {
                $result = $batch->executionResults[$key] ?? null;
                if (!$result instanceof ToolCallResult) {
                    throw new \RuntimeException('Authorized tool result is missing.');
                }
                yield $result;
            } elseif ('OutcomeUnknown' === $authorization['state'] && !isset($authorization['unknown_notice_transition'])) {
                $invocation = $authorization['invocation'] ?? null;
                if (!\is_array($invocation) || !\is_int($invocation['attempt'] ?? null) || !\is_string($invocation['key'] ?? null) || !\is_string($invocation['call_id'] ?? null) || !\is_string($authorization['claim'])) {
                    throw new \RuntimeException('Unknown tool execution lacks its scalar invocation receipt.');
                }
                yield new \Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown($runId, $turnNo, $stepId, $invocation['attempt'], $invocation['key'], $invocation['call_id'], $key, $authorization['claim']);
            }
        }
    }

    public function recoverRunning(string $runId, int $turnNo, string $stepId): void
    {
        $this->runLocks->synchronized($runId, fn () => $this->recoverRunningUnderRunLock($runId, $turnNo, $stepId));
    }

    public function unknownNoticePending(\Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown $notice): bool
    {
        $batch = $this->store->load($notice->runId(), $notice->turnNo(), $notice->stepId());
        $receipt = $batch?->executionAuthorizations[$notice->authorizationId] ?? null;
        if (null !== $receipt && 'Stale' === $receipt['state'] && isset($receipt['unknown_repair_transition']) && $receipt['claim'] === $notice->claimToken
            && ($receipt['invocation'] ?? null) === ['attempt' => $notice->attempt(), 'key' => $notice->idempotencyKey(), 'call_id' => $notice->toolCallId]) {
            return false;
        }
        if (null === $receipt || 'OutcomeUnknown' !== $receipt['state'] || $receipt['claim'] !== $notice->claimToken
            || ($receipt['invocation'] ?? null) !== ['attempt' => $notice->attempt(), 'key' => $notice->idempotencyKey(), 'call_id' => $notice->toolCallId]) {
            throw new \RuntimeException('Unknown tool notice differs from its durable receipt.');
        }

        return !isset($receipt['unknown_notice_transition']);
    }

    public function matchesCurrentInvocation(\Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown $notice): bool
    {
        $call = $this->store->load($notice->runId(), $notice->turnNo(), $notice->stepId())?->calls[$notice->toolCallId] ?? null;

        return $call instanceof ExecuteToolCall && $this->identity($call) === $notice->authorizationId;
    }

    public function consumeUnknownNotice(\Ineersa\AgentCore\Domain\Coordination\ConsumeToolExecutionUnknownDTO $action, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
    {
        $matched = false;
        foreach ($transition->work['actions'] ?? [] as $expected) {
            if ($expected instanceof \Ineersa\AgentCore\Domain\Coordination\ConsumeToolExecutionUnknownDTO && (array) $expected->notice === (array) $action->notice) {
                $matched = true;
            }
        }
        if (!$matched || ($transition->work['run_id'] ?? null) !== $action->notice->runId()) {
            throw new \RuntimeException('Unknown tool acknowledgement has no verified owner decision.');
        }
        $this->unknownNoticePending($action->notice);
        $notice = $action->notice;
        $this->store->mutate($notice->runId(), $notice->turnNo(), $notice->stepId(), static function (?ToolBatchStateDTO $batch) use ($notice, $transition): ToolBatchStoreMutation {
            $receipt = $batch?->executionAuthorizations[$notice->authorizationId] ?? null;
            if (null === $batch || null === $receipt || 'OutcomeUnknown' !== $receipt['state'] || $receipt['claim'] !== $notice->claimToken) {
                throw new \RuntimeException('Unknown tool acknowledgement lost its durable receipt.');
            }
            if (isset($receipt['unknown_notice_transition']) && $receipt['unknown_notice_transition'] !== $transition->identity) {
                throw new \RuntimeException('Conflicting unknown tool acknowledgement.');
            }
            $batch->executionAuthorizations[$notice->authorizationId] = [...$receipt, 'unknown_notice_transition' => $transition->identity];

            return new ToolBatchStoreMutation(null, $batch);
        });
    }

    public function assertNoUnknownExecution(string $runId): void
    {
        if ($this->store->hasOutcomeUnknown($runId)) {
            throw new \RuntimeException(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown::ERROR_MESSAGE);
        }
    }

    public function saveResult(ExecuteToolCall $call, string $claim, ToolCallResult $result): void
    {
        $json = $this->serializer->serialize($result, 'json', [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]);
        if (\strlen($json) > 16777216) {
            throw new \RuntimeException('Tool execution result exceeds the durable record bound.');
        }
        $this->store->mutate($call->runId(), $call->turnNo(), $call->stepId(), function (?ToolBatchStateDTO $batch) use ($call, $claim, $result): ToolBatchStoreMutation {
            // A revised human-answer envelope must not erase the old claim's
            // eventual outcome. Authorization identity, not the mutable call
            // projection, fences this durable write.
            if (null === $batch) {
                throw new \RuntimeException('Tool execution batch evidence is missing.');
            }
            $key = $this->identity($call);
            $authorization = $batch->executionAuthorizations[$key] ?? null;
            if (null === $authorization || 'Running' !== $authorization['state'] || $claim !== $authorization['claim']) {
                throw new \RuntimeException('Tool execution result does not match its running claim.');
            }
            $batch->executionResults[$key] = $result;
            $batch->executionAuthorizations[$key] = ['state' => 'ResultReady', 'claim' => $claim];

            return new ToolBatchStoreMutation(null, $batch);
        });
    }

    /** Called only after the existing deferred repository committed its registration. */
    public function transferToDeferred(ExecuteToolCall $call, string $deferredId): void
    {
        $correlation = $this->deferredRepository->findByDeferredId($deferredId);
        if (null === $correlation || $correlation->runId !== $call->runId() || $correlation->turnNo !== $call->turnNo()
            || $correlation->stepId !== $call->stepId() || $correlation->toolCallId !== $call->toolCallId) {
            throw new \RuntimeException('Deferred transfer has no matching durable repository registration.');
        }
        $this->store->mutate($call->runId(), $call->turnNo(), $call->stepId(), function (?ToolBatchStateDTO $batch) use ($call, $deferredId): ToolBatchStoreMutation {
            $this->requireCall($batch, $call);
            $key = $this->identity($call);
            $authorization = $batch->executionAuthorizations[$key] ?? null;
            if (null === $authorization || !\in_array($authorization['state'], ['Running', 'Deferred'], true)) {
                throw new \RuntimeException('Deferred transfer requires an existing execution claim.');
            }
            if ('Deferred' === $authorization['state'] && ($authorization['deferred_id'] ?? null) !== $deferredId) {
                throw new \RuntimeException('Conflicting deferred execution ownership.');
            }
            $batch->executionAuthorizations[$key] = ['state' => 'Deferred', 'claim' => $authorization['claim'], 'deferred_id' => $deferredId];

            return new ToolBatchStoreMutation(null, $batch);
        });
    }

    public function isDisposed(ToolCallResult $result): bool
    {
        $batch = $this->store->load($result->runId(), $result->turnNo(), $result->stepId());
        if (null === $batch) {
            return false;
        }
        $hasStoredOutcome = false;
        foreach ($batch->executionResults as $key => $stored) {
            if ($stored->toolCallId !== $result->toolCallId) {
                continue;
            }
            $hasStoredOutcome = true;
            if ($this->resultHash($stored) === $this->resultHash($result)) {
                return \in_array($batch->executionAuthorizations[$key]['state'] ?? null, ['Consumed', 'Stale'], true);
            }
        }
        if ($hasStoredOutcome) {
            throw new \RuntimeException('Incoming tool result differs from the durable worker outcome.');
        }

        $call = $batch->calls[$result->toolCallId] ?? null;
        if ($call instanceof ExecuteToolCall) {
            $state = $batch->executionAuthorizations[$this->identity($call)]['state'] ?? null;

            return \in_array($state, ['Consumed', 'Stale'], true);
        }

        foreach ($batch->executionAuthorizations as $authorization) {
            $invocation = $authorization['invocation'] ?? null;
            if (($invocation['call_id'] ?? null) === $result->toolCallId
                && \in_array($authorization['state'], ['Consumed', 'Stale'], true)) {
                return true;
            }
        }

        return false;
    }

    public function prepareDisposition(ToolCallResult $result, string $disposition): ?\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO
    {
        return $this->store->mutate($result->runId(), $result->turnNo(), $result->stepId(), function (?ToolBatchStateDTO $batch) use ($result, $disposition): ToolBatchStoreMutation {
            if (null === $batch) {
                return new ToolBatchStoreMutation(null);
            }
            $hash = $this->resultHash($result);
            foreach ($batch->executionAuthorizations as $key => $authorization) {
                if ('Deferred' !== $authorization['state'] || isset($batch->executionResults[$key])) {
                    continue;
                }
                $deferredId = $authorization['deferred_id'] ?? null;
                $correlation = \is_string($deferredId) ? $this->deferredRepository->findByDeferredId($deferredId) : null;
                if (null !== $correlation && $correlation->runId === $result->runId() && $correlation->turnNo === $result->turnNo()
                    && $correlation->stepId === $result->stepId() && $correlation->toolCallId === $result->toolCallId) {
                    // The repository owns execution; capture the accepted owner
                    // completion once in the same batch before canonical append.
                    $batch->executionResults[$key] = $result;
                }
            }
            foreach ($batch->executionResults as $key => $stored) {
                if ($this->resultHash($stored) !== $hash) {
                    continue;
                }
                $authorization = $batch->executionAuthorizations[$key] ?? null;
                if (null === $authorization || !\is_string($authorization['claim'])) {
                    throw new \RuntimeException('Durable tool result has no matching claim evidence.');
                }
                if (\in_array($authorization['state'], ['Consumed', 'Stale'], true)) {
                    return new ToolBatchStoreMutation(null);
                }
                $descriptor = $batch->pendingDispositions[$key] ?? new \Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO($result->runId(), $result->turnNo(), $result->stepId(), $key, $authorization['claim'], $hash, $disposition);
                $batch->pendingDispositions[$key] = $descriptor;

                return new ToolBatchStoreMutation($descriptor, $batch);
            }

            return new ToolBatchStoreMutation(null);
        });
    }

    public function validateDisposition(\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO $descriptor, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
    {
        if (($transition->work['run_id'] ?? null) !== $descriptor->runId || (array) ($transition->work['result_disposition'] ?? null) !== (array) $descriptor) {
            throw new \RuntimeException('Disposition has no matching verified canonical transition.');
        }
        $batch = $this->store->load($descriptor->runId, $descriptor->turnNo, $descriptor->stepId);
        $authorization = $batch?->executionAuthorizations[$descriptor->operationId] ?? null;
        $result = $batch?->executionResults[$descriptor->operationId] ?? null;
        if (null === $authorization || !$result instanceof ToolCallResult || $authorization['claim'] !== $descriptor->claimToken || $this->resultHash($result) !== $descriptor->resultHash
            || !\in_array($authorization['state'], ['ResultReady', 'Deferred', 'Consumed', 'Stale'], true)
            || (isset($authorization['transition_identity']) && $authorization['transition_identity'] !== $transition->identity)
            || (\in_array($authorization['state'], ['Consumed', 'Stale'], true) && $authorization['state'] !== $descriptor->disposition)) {
            throw new \RuntimeException('Tool disposition evidence is missing or differs from its durable result.');
        }
    }

    public function applyDisposition(\Ineersa\AgentCore\Domain\Coordination\ToolResultDispositionDTO $descriptor, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
    {
        $this->validateDisposition($descriptor, $transition);
        if (($transition->work['run_id'] ?? null) !== $descriptor->runId || (array) ($transition->work['result_disposition'] ?? null) !== (array) $descriptor) {
            throw new \RuntimeException('Disposition has no matching verified canonical transition.');
        }
        $this->store->mutate($descriptor->runId, $descriptor->turnNo, $descriptor->stepId, function (?ToolBatchStateDTO $batch) use ($descriptor, $transition): ToolBatchStoreMutation {
            $authorization = $batch?->executionAuthorizations[$descriptor->operationId] ?? null;
            $result = $batch?->executionResults[$descriptor->operationId] ?? null;
            if (null === $batch || null === $authorization || !$result instanceof ToolCallResult
                || $authorization['claim'] !== $descriptor->claimToken || $this->resultHash($result) !== $descriptor->resultHash) {
                throw new \RuntimeException('Tool disposition evidence is missing or differs from its durable result.');
            }
            if (\in_array($authorization['state'], ['Consumed', 'Stale'], true) && $authorization['state'] !== $descriptor->disposition) {
                throw new \RuntimeException('Conflicting durable tool disposition.');
            }
            if (isset($authorization['transition_identity']) && $authorization['transition_identity'] !== $transition->identity) {
                throw new \RuntimeException('Tool result was disposed by a different transition.');
            }
            $batch->executionAuthorizations[$descriptor->operationId]['state'] = $descriptor->disposition;
            $batch->executionAuthorizations[$descriptor->operationId]['transition_identity'] = $transition->identity;
            unset($batch->pendingDispositions[$descriptor->operationId]);

            return new ToolBatchStoreMutation(null, $batch);
        });
    }

    public function reclaimDisposedPayloads(string $runId, string $afterFilename): string
    {
        return $this->store->reclaimDisposedPayloads($runId, $afterFilename);
    }

    private function withUnknownExclusion(\Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown $notice, callable $decision): void
    {
        $this->runLocks->synchronized($notice->runId(), function () use ($notice, $decision): void {
            $this->unknownNoticePending($notice);
            $receipt = $this->store->load($notice->runId(), $notice->turnNo(), $notice->stepId())?->executionAuthorizations[$notice->authorizationId] ?? null;
            $key = $receipt['claim_lock_key'] ?? null;
            if (null === $receipt || 'OutcomeUnknown' !== $receipt['state'] || !\is_string($key) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $key) || !str_starts_with($notice->claimToken, $key.'.')) {
                throw new \RuntimeException('Unknown tool repair has no matching ownership receipt.');
            }
            $lock = $this->claimLockFactory->createLock('tool-execution-worker.'.$key, ttl: null);
            if (!$lock->acquire()) {
                throw new \RuntimeException('Unknown tool repair refused: its original worker still owns execution.');
            }
            try {
                $decision();
            } finally {
                $lock->release();
            }
        });
    }

    private function claimUnderRunLock(ExecuteToolCall $call): string|ToolCallResult|null
    {
        // Keep exclusion for the worker instance lifetime, including failures
        // after external execution. Lease expiry or a PID cannot prove death.
        if (!$this->workerLock->acquire()) {
            throw new \RuntimeException('Unable to acquire tool execution worker ownership.');
        }

        return $this->store->mutate($call->runId(), $call->turnNo(), $call->stepId(), function (?ToolBatchStateDTO $batch) use ($call): ToolBatchStoreMutation {
            if (null === $batch) {
                throw new \RuntimeException('Tool execution is missing or differs from its durable batch invocation.');
            }
            $key = $this->identity($call);
            $authorization = $batch->executionAuthorizations[$key] ?? null;
            if (null === $authorization) {
                throw new \RuntimeException('Tool execution has no owner authorization.');
            }
            if ('ResultReady' === $authorization['state']) {
                $result = $batch->executionResults[$key] ?? null;
                if (!$result instanceof ToolCallResult) {
                    throw new \RuntimeException('Authorized tool result is missing.');
                }

                return new ToolBatchStoreMutation($result);
            }
            if ('Armed' !== $authorization['state']) {
                return new ToolBatchStoreMutation(null);
            }
            $this->executionOperations->assertNoUnknownExecution($call->runId());
            foreach ($batch->executionAuthorizations as $pending) {
                if ('OutcomeUnknown' === $pending['state']) {
                    throw new \RuntimeException(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown::ERROR_MESSAGE);
                }
            }
            $storedCall = $batch->calls[$call->toolCallId] ?? null;
            if (!$storedCall instanceof ExecuteToolCall || $this->identity($storedCall) !== $key) {
                throw new \RuntimeException('Tool execution differs from its current durable invocation.');
            }
            $claim = $this->instanceToken.'.'.bin2hex(random_bytes(32));
            $batch->executionAuthorizations[$key] = [...$authorization, 'state' => 'Running', 'claim' => $claim, 'claim_lock_key' => $this->instanceToken];

            return new ToolBatchStoreMutation($claim, $batch);
        });
    }

    private function recoverRunningUnderRunLock(string $runId, int $turnNo, string $stepId): void
    {
        $batch = $this->store->load($runId, $turnNo, $stepId);
        $receipts = $batch->executionAuthorizations ?? [];
        unset($batch);
        foreach ($receipts as $key => $receipt) {
            if ('Running' !== $receipt['state']) {
                continue;
            }
            $owner = $receipt['claim_lock_key'] ?? null;
            if (!\is_string($owner) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $owner) || !\is_string($receipt['claim']) || !str_starts_with($receipt['claim'], $owner.'.')) {
                throw new \RuntimeException('Running tool execution has no verifiable worker ownership.');
            }
            $lock = $this->claimLockFactory->createLock('tool-execution-worker.'.$owner, ttl: null);
            if (!$lock->acquire()) {
                continue;
            }
            try {
                $this->store->recoverResultPublication($runId, $turnNo, $stepId, $key, $receipt['claim']);
                $this->store->mutate($runId, $turnNo, $stepId, function (?ToolBatchStateDTO $current) use ($runId, $turnNo, $stepId, $key, $receipt): ToolBatchStoreMutation {
                    $actual = $current?->executionAuthorizations[$key] ?? null;
                    if (null === $current || $actual !== $receipt) {
                        return new ToolBatchStoreMutation(null);
                    }
                    if (isset($current->executionResults[$key])) {
                        // Result and ResultReady publish together. Running with a
                        // result cannot be an interrupted successful publication.
                        throw new \RuntimeException('Running tool execution has inconsistent durable result evidence.');
                    }
                    $identity = $actual['invocation'] ?? null;
                    if (null === $identity) {
                        throw new \RuntimeException('Running tool execution lacks its scalar invocation receipt.');
                    }
                    $deferred = $this->deferredRepository->findByRunAndToolCall($runId, $identity['call_id']);
                    $storedCall = $current->calls[$identity['call_id']] ?? null;
                    if (null !== $deferred && $storedCall instanceof ExecuteToolCall && $this->identity($storedCall) === $key
                        && $deferred->turnNo === $turnNo && $deferred->stepId === $stepId && $deferred->attempt === $identity['attempt'] && $deferred->idempotencyKey === $identity['key']) {
                        $current->executionAuthorizations[$key] = [...$actual, 'state' => 'Deferred', 'deferred_id' => $deferred->deferredId];
                    } else {
                        $current->executionAuthorizations[$key] = [...$actual, 'state' => 'OutcomeUnknown'];
                    }

                    return new ToolBatchStoreMutation(null, $current);
                });
            } finally {
                $lock->release();
            }
        }
    }

    private function resultHash(ToolCallResult $result): string
    {
        return hash('sha256', $this->serializer->serialize($result, 'json', [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]));
    }

    private function identity(ExecuteToolCall $call): string
    {
        return hash('sha256', $this->serializer->serialize($call, 'json', [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]));
    }

    /** @phpstan-assert ToolBatchStateDTO $batch */
    private function requireCall(?ToolBatchStateDTO $batch, ExecuteToolCall $call): void
    {
        $stored = $batch?->calls[$call->toolCallId] ?? null;
        if (null === $batch || !$stored instanceof ExecuteToolCall || $this->identity($stored) !== $this->identity($call)) {
            throw new \RuntimeException('Tool execution is missing or differs from its durable batch invocation.');
        }
    }
}
