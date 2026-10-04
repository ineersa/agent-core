<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/** Execution authorization belongs to the existing durable batch, never the delivery. */
final readonly class ToolExecutionAuthorization implements \Ineersa\AgentCore\Contract\Tool\ToolExecutionAuthorizationInterface
{
    private string $instanceToken;

    public function __construct(private ToolBatchStoreInterface $store, private SerializerInterface $serializer, private \Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface $deferredRepository)
    {
        $this->instanceToken = bin2hex(random_bytes(32));
    }

    public function arm(ExecuteToolCall $call): void
    {
        $this->store->mutate($call->runId(), $call->turnNo(), $call->stepId(), function (?ToolBatchStateDTO $batch) use ($call): ToolBatchStoreMutation {
            $this->requireCall($batch, $call);
            $key = $this->identity($call);
            if (!isset($batch->executionAuthorizations[$key])) {
                $batch->executionAuthorizations[$key] = ['state' => 'Armed', 'claim' => null];
            }

            return new ToolBatchStoreMutation(null, $batch);
        });
    }

    /** A duplicate Running delivery returns null. A durable result is returned unchanged. */
    public function claim(ExecuteToolCall $call): string|ToolCallResult|null
    {
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
            $storedCall = $batch->calls[$call->toolCallId] ?? null;
            if (!$storedCall instanceof ExecuteToolCall || $this->identity($storedCall) !== $key) {
                throw new \RuntimeException('Tool execution differs from its current durable invocation.');
            }
            $claim = $this->instanceToken.'.'.bin2hex(random_bytes(32));
            $batch->executionAuthorizations[$key] = ['state' => 'Running', 'claim' => $claim];

            return new ToolBatchStoreMutation($claim, $batch);
        });
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
