<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ineersa\AgentCore\Application\Handler\ExecutionOperationMapper;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/** Atomic scalar authorization rows; request/result bodies are private immutable files. */
final readonly class DoctrineExecutionOperationStore implements ExecutionOperationStoreInterface
{
    private const int MAX_PAYLOAD_BYTES = 16777216;
    private string $instance;
    private LockInterface $workerLock;

    public function __construct(private Connection $connection, private ToolBatchRunStoragePathsInterface $paths, private Filesystem $filesystem, #[Autowire(service: 'hatfield.controller.session_owner.lock_factory')] private LockFactory $claimLockFactory, private RunLockManager $runLocks, private ToolBatchStoreInterface $toolBatches)
    {
        $this->instance = bin2hex(random_bytes(32));
        $this->workerLock = $claimLockFactory->createLock('execution-worker.'.$this->instance, ttl: null);
    }

    public function unknownExecutionsForRepair(string $runId): array
    {
        $records = $this->connection->fetchAllAssociative("SELECT * FROM execution_operation WHERE run_id = ? AND state = 'OutcomeUnknown' ORDER BY effect_id LIMIT 33", [$runId]);
        if (\count($records) > 32) {
            throw new \RuntimeException('Unknown execution repair exceeds the bounded decision capacity.');
        }

        return array_map($this->unknownNotice(...), $records);
    }

    public function assertUnknownRepairable(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown $notice): void
    {
        $this->withUnknownExclusion($notice, static fn () => null);
    }

    public function retireUnknownExecution(\Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO $action, VerifiedTransitionDTO $transition): void
    {
        $action->verifyTransition($transition);
        $notice = $action->notice;
        if (!$notice instanceof \Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown) {
            throw new \RuntimeException('Generic retirement requires a generic execution receipt.');
        }
        $this->runLocks->synchronized($notice->runId(), function () use ($notice, $transition): void {
            $record = $this->record($notice->effectId);
            if ((array) $this->unknownNotice($record) !== (array) $notice) {
                throw new \RuntimeException('Unknown retirement differs from its execution receipt.');
            }
            if ('Stale' === $record['state'] && $record['disposition_transition'] === $transition->identity && null === $record['result_hash']) {
                $this->removePayloadDirectory($record['run_id'], $record['effect_id']);

                return;
            }
            $this->withUnknownExclusion($notice, function () use ($notice, $transition): void {
                $updated = $this->connection->executeStatement("UPDATE execution_operation SET state = 'Stale', disposition_transition = ? WHERE effect_id = ? AND claim_token = ? AND state = 'OutcomeUnknown'", [$transition->identity, $notice->effectId, $notice->claimToken]);
                if (1 !== $updated) {
                    throw new \RuntimeException('Unknown execution retirement lost its precise receipt.');
                }
                $this->removePayloadDirectory($notice->runId(), $notice->effectId);
            });
        });
    }

    public function repairDelivery(string $runId, \Ineersa\AgentCore\Domain\Run\CurrentOperationDTO $operation, string $requestType): ?Envelope
    {
        $records = $this->connection->fetchAllAssociative('SELECT * FROM execution_operation WHERE run_id = ? AND turn_no = ? AND step_id = ? AND attempt = ? AND idempotency_key = ? AND request_type = ? ORDER BY effect_id LIMIT 2', [$runId, $operation->turnNo, $operation->stepId, $operation->attempt, $operation->idempotencyKey, $requestType]);
        if ([] === $records) {
            return null;
        }
        if (1 !== \count($records)) {
            throw new \RuntimeException('Current execution identity has ambiguous authorization evidence.');
        }
        $record = $records[0];
        if ('ResultReady' === $record['state']) {
            return new Envelope($this->reference($record));
        }
        if ('Armed' !== $record['state']) {
            return null;
        }
        // Repair never replaces the frozen input with current reconstructed messages.
        $this->readSealed($this->path($runId, $record['effect_id'], 'request'), $record['request_hash'], (int) $record['request_bytes']);
        $reference = new ExecutionRequest($record['run_id'], (int) $record['turn_no'], $record['step_id'], (int) $record['attempt'], $record['idempotency_key'], $record['effect_id'], $record['request_type'], $record['request_hash'], (int) $record['request_bytes']);

        return new Envelope($reference, [new ExecutionAuthorizationStamp($record['effect_id'], $record['request_hash'])]);
    }

    public function arm(AbstractAgentBusMessage $request, VerifiedTransitionDTO $transition): ExecutionAuthorizationStamp
    {
        if (!ExecutionOperationMapper::supports($request) || ($transition->work['run_id'] ?? null) !== $request->runId()) {
            throw new \RuntimeException('Execution authorization requires matching verified owner work.');
        }
        $bytes = $this->encodeRequest($request);
        $hash = hash('sha256', $bytes);
        $matched = false;
        foreach ([...($transition->work['effects'] ?? []), ...($transition->work['post_commit_effects'] ?? [])] as $effect) {
            if ($effect instanceof AbstractAgentBusMessage && $effect::class === $request::class && hash('sha256', $this->encodeRequest($effect)) === $hash) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new \RuntimeException('Execution request is absent from verified owner work.');
        }
        $id = hash('sha256', $transition->identity.'|'.$hash);
        $this->seal($this->path($request->runId(), $id, 'request'), $bytes);
        try {
            $this->connection->insert('execution_operation', [
                'effect_id' => $id, 'run_id' => $request->runId(), 'turn_no' => $request->turnNo(), 'step_id' => $request->stepId(), 'attempt' => $request->attempt(), 'idempotency_key' => $request->idempotencyKey(),
                'request_type' => $request::class, 'result_type' => ExecutionOperationMapper::resultType($request), 'request_hash' => $hash, 'request_bytes' => \strlen($bytes), 'owner_generation' => $transition->identity, 'state' => 'Prepared',
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->record($id);
            if ($existing['request_hash'] !== $hash || $existing['owner_generation'] !== $transition->identity) {
                throw new \RuntimeException('Conflicting execution authorization.', previous: $exception);
            }
            // Intent recovery repeats the same immutable insertion, never resets a claim.
        }
        $this->connection->executeStatement("UPDATE execution_operation SET state = 'Armed' WHERE effect_id = ? AND state = 'Prepared'", [$id]);

        return new ExecutionAuthorizationStamp($id, $hash);
    }

    public function requestReference(AbstractAgentBusMessage $request, ExecutionAuthorizationStamp $authorization): ExecutionRequest
    {
        $record = $this->record($authorization->effectId);
        $hash = hash('sha256', $this->encodeRequest($request));
        if ($record['request_hash'] !== $authorization->requestHash || $hash !== $authorization->requestHash || $record['request_type'] !== $request::class || $record['run_id'] !== $request->runId()) {
            throw new \RuntimeException('Execution delivery differs from its owner authorization.');
        }

        return new ExecutionRequest($request->runId(), $request->turnNo(), $request->stepId(), $request->attempt(), $request->idempotencyKey(), $authorization->effectId, $request::class, $hash, (int) $record['request_bytes']);
    }

    /**
     * Durable reservations scope descendants even when disposable operational rows disappear.
     * Only scalar references are read here, never the sealed invocation bodies.
     *
     * @return array<string, Envelope|null> keyed by the stable effect identity, including live claims to advance the page cursor
     */
    public function pendingDeliveries(string $ownerSessionId, string $afterEffectId): array
    {
        $records = $this->connection->fetchAllAssociative(<<<'SQL'
            WITH RECURSIVE owned_runs(run_id) AS (
                SELECT :owner
                UNION
                SELECT child.child_run_id
                FROM deferred_subagent_child child
                JOIN deferred_subagent_batch batch ON batch.lifecycle_id = child.batch_lifecycle_id
                JOIN owned_runs parent ON parent.run_id = batch.parent_run_id
            )
            SELECT operation.* FROM execution_operation operation
            JOIN owned_runs owned ON owned.run_id = operation.run_id
            WHERE (operation.state IN ('Armed', 'Running', 'ResultReady') OR (operation.state = 'OutcomeUnknown' AND operation.unknown_notice_transition IS NULL)) AND operation.effect_id > :after
            ORDER BY operation.effect_id LIMIT 32
            SQL, ['owner' => $ownerSessionId, 'after' => $afterEffectId]);
        $deliveries = [];
        foreach ($records as $record) {
            if ('Running' === $record['state']) {
                $record = $this->recoverClaim($record);
            }
            if ('Running' === $record['state']) {
                $deliveries[$record['effect_id']] = null;
                continue;
            }
            if ('OutcomeUnknown' === $record['state']) {
                $deliveries[$record['effect_id']] = new Envelope($this->unknownNotice($record));
                continue;
            }
            if ('ResultReady' === $record['state']) {
                $deliveries[$record['effect_id']] = new Envelope($this->reference($record));
                continue;
            }
            $stamp = new ExecutionAuthorizationStamp($record['effect_id'], $record['request_hash']);
            $request = new ExecutionRequest($record['run_id'], (int) $record['turn_no'], $record['step_id'], (int) $record['attempt'], $record['idempotency_key'], $record['effect_id'], $record['request_type'], $record['request_hash'], (int) $record['request_bytes']);
            $deliveries[$record['effect_id']] = new Envelope($request, [$stamp]);
        }

        return $deliveries;
    }

    public function claim(ExecutionRequest $request, ExecutionAuthorizationStamp $authorization): string|DurableExecutionResult|null
    {
        return $this->runLocks->synchronized($request->runId(), fn (): string|DurableExecutionResult|null => $this->claimUnderRunLock($request, $authorization));
    }

    public function resolveRequest(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization, string $claim): AbstractAgentBusMessage
    {
        $record = $this->matchingRequest($reference, $authorization);
        if ('Running' !== $record['state'] || $record['claim_token'] !== $claim) {
            throw new \RuntimeException('Execution input requires its running claim.');
        }
        $bytes = $this->readSealed($this->path($reference->runId(), $reference->effectId, 'request'), $reference->sha256, $reference->bytes);
        $request = (new PhpSerializer())->decode(['body' => $bytes])->getMessage();
        if (!$request instanceof AbstractAgentBusMessage || !ExecutionOperationMapper::supports($request) || $request::class !== $reference->requestType
            || $request->runId() !== $reference->runId() || $request->turnNo() !== $reference->turnNo() || $request->stepId() !== $reference->stepId() || $request->attempt() !== $reference->attempt() || $request->idempotencyKey() !== $reference->idempotencyKey()) {
            throw new \RuntimeException('Sealed execution request identity mismatch.');
        }

        return $request;
    }

    public function resultForClaim(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization, string $claim): DurableExecutionResult
    {
        $record = $this->matchingRequest($reference, $authorization);
        if ($record['claim_token'] !== $claim || !\in_array($record['state'], ['ResultReady', 'Consumed', 'Stale'], true)) {
            throw new \RuntimeException('Execution handler returned without a durable result.');
        }
        $result = $this->reference($record);
        if (!$this->payloadReclaimed($record)) {
            $this->readSealed($this->path($reference->runId(), $reference->effectId, hash('sha256', $claim).'.result'), $result->sha256, $result->bytes);
        }

        return $result;
    }

    public function saveResult(AbstractAgentBusMessage $request, ExecutionAuthorizationStamp $authorization, string $claim, AbstractAgentBusMessage $result): DurableExecutionResult
    {
        $record = $this->record($authorization->effectId);
        if ($record['request_hash'] !== $authorization->requestHash || $record['claim_token'] !== $claim || !\in_array($record['state'], ['Running', 'ResultReady'], true)
            || $result::class !== $record['result_type'] || $result->runId() !== $request->runId() || $result->turnNo() !== $request->turnNo() || $result->stepId() !== $request->stepId() || $result->attempt() !== $request->attempt() || $result->idempotencyKey() !== $request->idempotencyKey()) {
            throw new \RuntimeException('Execution result differs from its running authorization.');
        }
        $body = (new PhpSerializer())->encode(new Envelope($result))['body'];
        $bytes = json_encode(['schema' => 1, 'effect_id' => $authorization->effectId, 'claim_token' => $claim, 'body' => $body, 'sha256' => hash('sha256', $body), 'bytes' => \strlen($body)], \JSON_THROW_ON_ERROR);
        $this->checkBound($bytes);
        $hash = hash('sha256', $bytes);
        // The deterministic claim file permits later adoption after file publication
        // but before ResultReady. No missing notification can authorize a second call.
        $this->seal($this->path($request->runId(), $authorization->effectId, hash('sha256', $claim).'.result'), $bytes);
        $updated = $this->connection->executeStatement("UPDATE execution_operation SET state = 'ResultReady', result_hash = ?, result_bytes = ? WHERE effect_id = ? AND claim_token = ? AND state = 'Running'", [$hash, \strlen($bytes), $authorization->effectId, $claim]);
        $current = $this->record($authorization->effectId);
        if ((1 !== $updated && 'ResultReady' !== $current['state']) || $current['result_hash'] !== $hash || (int) $current['result_bytes'] !== \strlen($bytes)) {
            throw new \RuntimeException('Conflicting durable execution result.');
        }

        return $this->reference($current);
    }

    public function resolveResult(DurableExecutionResult $reference): AbstractAgentBusMessage
    {
        $record = $this->matchingResult($reference);
        if ($this->payloadReclaimed($record)) {
            throw new \RuntimeException('Disposed execution payload was reclaimed.');
        }
        $bytes = $this->readSealed($this->path($reference->runId(), $reference->effectId, hash('sha256', $reference->claimToken).'.result'), $reference->sha256, $reference->bytes);

        return $this->decodeResultSeal($bytes, $record);
    }

    public function isDisposed(DurableExecutionResult $reference): bool
    {
        return \in_array($this->matchingResult($reference)['state'], ['Consumed', 'Stale'], true);
    }

    public function validateDisposition(ExecutionResultDispositionDTO $descriptor, VerifiedTransitionDTO $transition): void
    {
        $expected = $transition->work['execution_disposition'] ?? null;
        if (!$expected instanceof ExecutionResultDispositionDTO || $expected->disposition !== $descriptor->disposition || (array) $expected->result !== (array) $descriptor->result || ($transition->work['run_id'] ?? null) !== $descriptor->result->runId()) {
            throw new \RuntimeException('Execution disposition has no matching verified transition.');
        }
        $record = $this->matchingResult($descriptor->result);
        // Disposition validation must succeed after payload reclaim. The sealed
        // result is verified only while the body is still required for ownership.
        if (!$this->payloadReclaimed($record)) {
            $this->resolveResult($descriptor->result);
        }
        if ((null !== $record['disposition_transition'] && $record['disposition_transition'] !== $transition->identity)
            || (\in_array($record['state'], ['Consumed', 'Stale'], true) && $record['state'] !== $descriptor->disposition)) {
            throw new \RuntimeException('Conflicting execution result disposition.');
        }
    }

    public function applyDisposition(ExecutionResultDispositionDTO $descriptor, VerifiedTransitionDTO $transition): void
    {
        $this->validateDisposition($descriptor, $transition);
        $updated = $this->connection->executeStatement("UPDATE execution_operation SET state = ?, disposition_transition = ? WHERE effect_id = ? AND claim_token = ? AND result_hash = ? AND state = 'ResultReady'", [$descriptor->disposition, $transition->identity, $descriptor->result->effectId, $descriptor->result->claimToken, $descriptor->result->sha256]);
        if (1 !== $updated) {
            $record = $this->record($descriptor->result->effectId);
            if ($record['state'] !== $descriptor->disposition || $record['disposition_transition'] !== $transition->identity) {
                throw new \RuntimeException('Execution disposition could not be persisted.');
            }
        }
    }

    public function reclaimDisposedPayloads(string $ownerSessionId, string $afterEffectId): string
    {
        if ('' === trim($ownerSessionId) || 'unknown' === $ownerSessionId) {
            return '';
        }
        $records = $this->connection->fetchAllAssociative(<<<'SQL'
            WITH RECURSIVE owned_runs(run_id) AS (
                SELECT :owner
                UNION
                SELECT child.child_run_id
                FROM deferred_subagent_child child
                JOIN deferred_subagent_batch batch ON batch.lifecycle_id = child.batch_lifecycle_id
                JOIN owned_runs parent ON parent.run_id = batch.parent_run_id
            )
            SELECT operation.* FROM execution_operation operation
            JOIN owned_runs owned ON owned.run_id = operation.run_id
            WHERE operation.state IN ('Consumed', 'Stale') AND operation.disposition_transition IS NOT NULL AND operation.effect_id > :after
            ORDER BY operation.effect_id LIMIT 32
            SQL, ['owner' => $ownerSessionId, 'after' => $afterEffectId]);
        $cursor = '';
        foreach ($records as $record) {
            $cursor = (string) $record['effect_id'];
            $this->runLocks->synchronized($record['run_id'], function () use ($record): void {
                $current = $this->record($record['effect_id']);
                if (!\in_array($current['state'], ['Consumed', 'Stale'], true) || null === $current['disposition_transition']) {
                    return;
                }
                if ($this->payloadReclaimed($current)) {
                    return;
                }
                $this->removePayloadDirectory($current['run_id'], $current['effect_id']);
            });
        }

        return $cursor;
    }

    public function unknownNoticePending(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown $notice): bool
    {
        $record = $this->record($notice->effectId);
        if ('Stale' === $record['state'] && null !== $record['disposition_transition'] && null === $record['result_hash'] && (array) $this->unknownNotice($record) === (array) $notice) {
            return false;
        }
        if ('OutcomeUnknown' !== $record['state'] || (array) $this->unknownNotice($record) !== (array) $notice) {
            throw new \RuntimeException('Unknown execution notice differs from its durable receipt.');
        }

        return null === $record['unknown_notice_transition'];
    }

    public function consumeUnknownNotice(\Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO $action, VerifiedTransitionDTO $transition): void
    {
        $matched = false;
        foreach ($transition->work['actions'] ?? [] as $expected) {
            if ($expected instanceof \Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO && (array) $expected->notice === (array) $action->notice) {
                $matched = true;
            }
        }
        if (!$matched || ($transition->work['run_id'] ?? null) !== $action->notice->runId()) {
            throw new \RuntimeException('Unknown notice acknowledgement has no verified owner decision.');
        }
        $this->unknownNoticePending($action->notice);
        $record = $this->record($action->notice->effectId);
        if (null !== $record['unknown_notice_transition'] && $record['unknown_notice_transition'] !== $transition->identity) {
            throw new \RuntimeException('Conflicting unknown notice acknowledgement.');
        }
        $this->connection->executeStatement("UPDATE execution_operation SET unknown_notice_transition = ? WHERE effect_id = ? AND claim_token = ? AND state = 'OutcomeUnknown' AND unknown_notice_transition IS NULL", [$transition->identity, $action->notice->effectId, $action->notice->claimToken]);
    }

    public function assertNoUnknownExecution(string $runId): void
    {
        if (false !== $this->connection->fetchOne("SELECT effect_id FROM execution_operation WHERE run_id = ? AND state = 'OutcomeUnknown' LIMIT 1", [$runId])) {
            throw new \RuntimeException(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown::ERROR_MESSAGE);
        }
    }

    private function withUnknownExclusion(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown $notice, callable $decision): void
    {
        $this->runLocks->synchronized($notice->runId(), function () use ($notice, $decision): void {
            $record = $this->record($notice->effectId);
            $key = $record['claim_lock_key'];
            if ('OutcomeUnknown' !== $record['state'] || (array) $this->unknownNotice($record) !== (array) $notice
                || !\is_string($key) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $key) || $record['worker_instance'] !== $key || !str_starts_with($notice->claimToken, $key.'.')) {
                throw new \RuntimeException('Unknown execution repair has no matching ownership receipt.');
            }
            $lock = $this->claimLockFactory->createLock('execution-worker.'.$key, ttl: null);
            if (!$lock->acquire()) {
                throw new \RuntimeException('Unknown execution repair refused: its original worker still owns execution.');
            }
            try {
                $decision();
            } finally {
                $lock->release();
            }
        });
    }

    private function claimUnderRunLock(ExecutionRequest $request, ExecutionAuthorizationStamp $authorization): string|DurableExecutionResult|null
    {
        $record = $this->matchingRequest($request, $authorization);
        if ('ResultReady' === $record['state']) {
            return $this->reference($record);
        }
        if ('Armed' !== $record['state']) {
            return null;
        }
        if ($this->toolBatches->hasOutcomeUnknown($request->runId())) {
            throw new \RuntimeException(\Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown::ERROR_MESSAGE);
        }
        // Publish no Running receipt until process-owned, nonexpiring exclusion
        // is held. Retain it for the store/worker lifetime, including exceptions.
        if (!$this->workerLock->isAcquired() && !$this->workerLock->acquire()) {
            throw new \RuntimeException('Unable to acquire execution worker ownership.');
        }
        $claim = $this->instance.'.'.bin2hex(random_bytes(32));
        $updated = $this->connection->executeStatement("UPDATE execution_operation SET state = 'Running', claim_token = ?, worker_instance = ?, worker_pid = ?, claim_lock_key = ? WHERE effect_id = ? AND state = 'Armed' AND request_hash = ? AND NOT EXISTS (SELECT 1 FROM execution_operation pending WHERE pending.run_id = ? AND pending.state = 'OutcomeUnknown')", [$claim, $this->instance, getmypid(), $this->instance, $authorization->effectId, $request->sha256, $request->runId()]);
        if (1 !== $updated) {
            $current = $this->record($authorization->effectId);

            return 'ResultReady' === $current['state'] ? $this->reference($current) : null;
        }

        return $claim;
    }

    /** @return array<string, mixed> */
    private function matchingRequest(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization): array
    {
        $record = $this->record($authorization->effectId);
        if ($reference->effectId !== $authorization->effectId || $reference->sha256 !== $authorization->requestHash || $record['request_hash'] !== $reference->sha256
            || (int) $record['request_bytes'] !== $reference->bytes || $record['request_type'] !== $reference->requestType || $record['run_id'] !== $reference->runId()
            || (int) $record['turn_no'] !== $reference->turnNo() || $record['step_id'] !== $reference->stepId() || (int) $record['attempt'] !== $reference->attempt() || $record['idempotency_key'] !== $reference->idempotencyKey()) {
            throw new \RuntimeException('Execution reference differs from its owner authorization.');
        }

        return $record;
    }

    /** @param array<string, mixed> $record */
    private function unknownNotice(array $record): \Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown
    {
        return new \Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown($record['run_id'], (int) $record['turn_no'], $record['step_id'], (int) $record['attempt'], $record['idempotency_key'], $record['effect_id'], $record['claim_token']);
    }

    /** @return array<string, mixed> */
    private function record(string $id): array
    {
        $record = $this->connection->fetchAssociative('SELECT * FROM execution_operation WHERE effect_id = ?', [$id]);
        if (false === $record) {
            throw new \RuntimeException('Execution delivery has no durable authorization.');
        }

        return $record;
    }

    /** @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function recoverClaim(array $record): array
    {
        return $this->runLocks->synchronized($record['run_id'], fn (): array => $this->recoverClaimUnderRunLock($record));
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function recoverClaimUnderRunLock(array $record): array
    {
        $key = $record['claim_lock_key'];
        if (!\is_string($key) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $key) || $key !== $record['worker_instance'] || !\is_string($record['claim_token']) || !str_starts_with($record['claim_token'], $key.'.')) {
            throw new \RuntimeException('Running execution has no verifiable ownership receipt.');
        }
        $lock = $this->claimLockFactory->createLock('execution-worker.'.$key, ttl: null);
        if (!$lock->acquire()) {
            return $record;
        }
        try {
            $current = $this->record($record['effect_id']);
            if ('Running' !== $current['state'] || $current['claim_token'] !== $record['claim_token'] || $current['claim_lock_key'] !== $key) {
                return $current;
            }
            $path = $this->path($record['run_id'], $record['effect_id'], hash('sha256', $record['claim_token']).'.result');
            if (is_link($path) || !is_readable(\dirname($path))) {
                throw new \RuntimeException('Execution result storage is unavailable or unsafe.');
            }
            if (is_file($path)) {
                $bytes = file_get_contents($path, false, null, 0, self::MAX_PAYLOAD_BYTES + 1);
                if (false === $bytes) {
                    throw new \RuntimeException('Unable to read sealed execution result.');
                }
                $this->checkBound($bytes);
                $this->decodeResultSeal($bytes, $current);
                $this->connection->executeStatement("UPDATE execution_operation SET state = 'ResultReady', result_hash = ?, result_bytes = ? WHERE effect_id = ? AND claim_token = ? AND claim_lock_key = ? AND state = 'Running'", [hash('sha256', $bytes), \strlen($bytes), $record['effect_id'], $record['claim_token'], $key]);
            } else {
                $this->connection->executeStatement("UPDATE execution_operation SET state = 'OutcomeUnknown' WHERE effect_id = ? AND claim_token = ? AND claim_lock_key = ? AND state = 'Running'", [$record['effect_id'], $record['claim_token'], $key]);
            }

            return $this->record($record['effect_id']);
        } finally {
            // Exclusion covers validation AND the durable decision, not a probe.
            $lock->release();
        }
    }

    /** @param array<string, mixed> $record */
    private function decodeResultSeal(string $bytes, array $record): AbstractAgentBusMessage
    {
        $seal = json_decode($bytes, true, 8, \JSON_THROW_ON_ERROR);
        if (!\is_array($seal) || ($seal['schema'] ?? null) !== 1 || ($seal['effect_id'] ?? null) !== $record['effect_id'] || ($seal['claim_token'] ?? null) !== $record['claim_token'] || !\is_string($seal['body'] ?? null)
            || ($seal['bytes'] ?? null) !== \strlen($seal['body']) || ($seal['sha256'] ?? null) !== hash('sha256', $seal['body'])) {
            throw new \RuntimeException('Sealed execution result evidence is corrupt.');
        }
        $result = (new PhpSerializer())->decode(['body' => $seal['body']])->getMessage();
        if (!$result instanceof AbstractAgentBusMessage || $result::class !== $record['result_type'] || $result->runId() !== $record['run_id'] || $result->turnNo() !== (int) $record['turn_no'] || $result->stepId() !== $record['step_id'] || $result->attempt() !== (int) $record['attempt'] || $result->idempotencyKey() !== $record['idempotency_key']) {
            throw new \RuntimeException('Sealed execution result identity mismatch.');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function matchingResult(DurableExecutionResult $reference): array
    {
        $record = $this->record($reference->effectId);
        if (!\in_array($record['state'], ['ResultReady', 'Consumed', 'Stale'], true) || $record['run_id'] !== $reference->runId() || (int) $record['turn_no'] !== $reference->turnNo() || $record['step_id'] !== $reference->stepId() || (int) $record['attempt'] !== $reference->attempt() || $record['idempotency_key'] !== $reference->idempotencyKey()
            || $record['claim_token'] !== $reference->claimToken || $record['result_hash'] !== $reference->sha256 || (int) $record['result_bytes'] !== $reference->bytes || $record['result_type'] !== $reference->resultType) {
            throw new \RuntimeException('Durable execution result reference mismatch.');
        }

        return $record;
    }

    /** @param array<string, mixed> $record */
    private function reference(array $record): DurableExecutionResult
    {
        if (!\is_string($record['claim_token']) || !\is_string($record['result_hash']) || !\is_string($record['result_type'])) {
            throw new \RuntimeException('Durable execution result evidence is incomplete.');
        }

        return new DurableExecutionResult((string) $record['run_id'], (int) $record['turn_no'], (string) $record['step_id'], (int) $record['attempt'], (string) $record['idempotency_key'], (string) $record['effect_id'], $record['claim_token'], $record['result_hash'], (int) $record['result_bytes'], $record['result_type']);
    }

    private function encodeRequest(AbstractAgentBusMessage $request): string
    {
        $bytes = (new PhpSerializer())->encode(new Envelope($request))['body'];
        $this->checkBound($bytes);

        return $bytes;
    }

    private function checkBound(string $bytes): void
    {
        if (\strlen($bytes) > self::MAX_PAYLOAD_BYTES) {
            throw new \RuntimeException('Execution payload exceeds its 16 MiB storage bound.');
        }
    }

    private function path(string $runId, string $id, string $name): string
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid execution operation identity.');
        }

        return \dirname($this->paths->resolveToolBatchesDirectory($runId)).'/execution-operations/'.$id.'/'.$name;
    }

    /** @param array<string, mixed> $record */
    private function payloadReclaimed(array $record): bool
    {
        return \in_array($record['state'], ['Consumed', 'Stale'], true)
            && null !== $record['disposition_transition']
            && !is_file($this->path($record['run_id'], $record['effect_id'], 'request'));
    }

    private function removePayloadDirectory(string $runId, string $effectId): void
    {
        $directory = \dirname($this->path($runId, $effectId, 'request'));
        if (!is_dir($directory)) {
            return;
        }
        try {
            $this->filesystem->remove($directory);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Disposed execution payload cleanup failed.', previous: $exception);
        }
        if (is_file($this->path($runId, $effectId, 'request'))) {
            throw new \RuntimeException('Disposed execution payload cleanup left evidence behind.');
        }
    }

    private function seal(string $path, string $bytes): void
    {
        $this->checkBound($bytes);
        $this->filesystem->mkdir(\dirname($path), 0700);
        if (is_link($path)) {
            throw new \RuntimeException('Execution payload cannot be a symbolic link.');
        }
        if (is_file($path)) {
            $this->readSealed($path, hash('sha256', $bytes), \strlen($bytes));

            return;
        }
        $this->filesystem->dumpFile($path, $bytes);
        $this->filesystem->chmod($path, 0600);
    }

    private function readSealed(string $path, string $hash, int $length): string
    {
        if ($length < 1 || $length > self::MAX_PAYLOAD_BYTES || !is_file($path) || is_link($path)) {
            throw new \RuntimeException('Execution payload is missing or invalid.');
        }
        $size = filesize($path);
        if ($size !== $length || hash_file('sha256', $path) !== $hash) {
            throw new \RuntimeException('Execution payload checksum or length mismatch.');
        }
        $bytes = file_get_contents($path);
        if (false === $bytes || \strlen($bytes) !== $length || hash('sha256', $bytes) !== $hash) {
            throw new \RuntimeException('Execution payload read failed verification.');
        }

        return $bytes;
    }
}
