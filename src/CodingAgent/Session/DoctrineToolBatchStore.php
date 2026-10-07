<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Application-DB scheduling authority for one (run, turn, step).
 *
 * Membership and queue state live here. Immutable request/result bodies and
 * claims stay on the common invocation ledger. Local writes join the owner
 * metadata transaction; applied_transition makes verified replay idempotent.
 */
final class DoctrineToolBatchStore implements ToolBatchStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ExecutionOperationStoreInterface $operations,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function load(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO
    {
        $this->sanitizeRunId($runId);
        $record = $this->connection->fetchAssociative(
            'SELECT * FROM tool_batch_schedule WHERE run_id = ? AND turn_no = ? AND step_id = ?',
            [$runId, $turnNo, $stepId],
        );

        return false === $record ? null : $this->hydrate($record);
    }


    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        $this->sanitizeRunId($runId);
        $this->connection->executeStatement(
            'DELETE FROM tool_batch_schedule WHERE run_id = ? AND turn_no = ? AND step_id = ?',
            [$runId, $turnNo, $stepId],
        );
    }

    public function deleteAllForRun(string $runId): void
    {
        $this->sanitizeRunId($runId);
        $this->connection->executeStatement(
            'DELETE FROM tool_batch_schedule WHERE run_id = ? AND finalized = 1 AND awaiting_human_input_json = ? AND pending_queue_json = ? AND in_flight_json = ?',
            [$runId, '{}', '[]', '[]'],
        );
    }

    public function hasUnresolvedExecution(string $runId, ?string $toolCallId = null): bool
    {
        $this->sanitizeRunId($runId);
        $records = $this->connection->fetchAllAssociative(
            'SELECT run_id, turn_no, step_id, finalized, awaiting_human_input_json, pending_queue_json, in_flight_json, calls_json FROM tool_batch_schedule WHERE run_id = ?',
            [$runId],
        );
        foreach ($records as $record) {
            if (null !== $toolCallId) {
                $calls = $this->jsonDecodeObject($record['calls_json']);
                if (!isset($calls[$toolCallId])) {
                    continue;
                }
            }
            if ($this->retainSchedulingEvidence($record)) {
                return true;
            }
        }

        return false;
    }



    public function applyPrepared(FinalizeToolBatchDTO $action, VerifiedTransitionDTO $transition): void
    {
        if (($transition->work['run_id'] ?? null) !== $action->runId) {
            throw new \RuntimeException('Prepared batch has no matching verified transition.');
        }
        $matched = false;
        foreach ($transition->work['actions'] ?? [] as $expected) {
            if ($expected instanceof FinalizeToolBatchDTO
                && $expected->runId === $action->runId
                && $expected->turnNo === $action->turnNo
                && $expected->stepId === $action->stepId
                && $expected->pendingQueue === $action->pendingQueue
                && $expected->inFlight === $action->inFlight
                && $expected->awaitingHumanInput === $action->awaitingHumanInput
                && $expected->finalized === $action->finalized
                && $expected->revisedCallId === $action->revisedCallId
                && ((null === $expected->answer && null === $action->answer) || (null !== $expected->answer && null !== $action->answer && $expected->answer->isEquivalent($action->answer)))
                && ((null === $expected->result && null === $action->result) || (null !== $expected->result && null !== $action->result && $expected->result->toolCallId === $action->result->toolCallId && $expected->result->idempotencyKey() === $action->result->idempotencyKey()))) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new \RuntimeException('Prepared batch has no matching verified transition.');
        }
        $this->sanitizeRunId($action->runId);
        if ($this->appliedTransition($action->runId, $action->turnNo, $action->stepId) === $transition->identity) {
            return;
        }
        $batch = $this->load($action->runId, $action->turnNo, $action->stepId);
        if (null === $batch) {
            throw new \RuntimeException('Prepared batch evidence is missing.');
        }
        $refs = $this->referencesFromBatch($action->runId, $action->turnNo, $action->stepId, $batch);
        $next = clone $batch;
        $next->pendingQueue = $action->pendingQueue;
        $next->inFlight = $action->inFlight;
        $next->awaitingHumanInput = $action->awaitingHumanInput;
        $next->finalized = $action->finalized;
        if (null !== $action->result) {
            $next->results[$action->result->toolCallId] = $action->result;
            $refs['results'][$action->result->toolCallId] = $this->resultReference($action->result);
        }
        if (null !== $action->revisedCallId) {
            $existing = $next->calls[$action->revisedCallId] ?? null;
            if (!$existing instanceof ExecuteToolCall) {
                throw new \RuntimeException('Prepared batch revision has no stored invocation.');
            }
            $revised = null === $action->answer
                ? $existing->withHumanInputAnswer(null)
                : $existing->withAuthorizedHumanAnswer($action->answer);
            $next->calls[$action->revisedCallId] = $revised;
            $refs['calls'][$action->revisedCallId] = $this->callReference($revised, $transition);
        }
        $this->upsert($action->runId, $action->turnNo, $action->stepId, $next, $refs, $transition->identity);
    }

    public function registerPrepared(RegisterToolBatchDTO $action, VerifiedTransitionDTO $transition): void
    {
        if (($transition->work['run_id'] ?? null) !== $action->runId) {
            throw new \RuntimeException('Prepared batch registration requires matching verified transition.');
        }
        $this->sanitizeRunId($action->runId);
        if ($this->appliedTransition($action->runId, $action->turnNo, $action->stepId) === $transition->identity) {
            return;
        }
        $effects = $action->effects;
        $callsById = [];
        $callRefs = [];
        foreach ($effects as $toolCall) {
            $callsById[$toolCall->toolCallId] = $toolCall;
            $callRefs[$toolCall->toolCallId] = $this->callReference($toolCall, $transition);
        }
        $existing = $this->load($action->runId, $action->turnNo, $action->stepId);
        if (null !== $existing) {
            if ($existing->expectedOrder !== $action->expectedOrder
                || $existing->pendingQueue !== $action->pendingQueue
                || $existing->inFlight !== $action->inFlight
                || $existing->maxParallelism !== $action->maxParallelism) {
                throw new \LogicException('Conflicting prepared tool batch membership.');
            }
            foreach ($callsById as $id => $call) {
                $stored = $existing->calls[$id] ?? null;
                if (null === $stored || $stored->runId() !== $call->runId() || $stored->turnNo() !== $call->turnNo()
                    || $stored->stepId() !== $call->stepId() || $stored->idempotencyKey() !== $call->idempotencyKey()
                    || $stored->toolName !== $call->toolName || $stored->args !== $call->args || (array) $stored->launchContext !== (array) $call->launchContext) {
                    throw new \LogicException('Conflicting prepared tool batch invocation.');
                }
            }
            $this->upsert($action->runId, $action->turnNo, $action->stepId, $existing, $this->referencesFromBatch($action->runId, $action->turnNo, $action->stepId, $existing), $transition->identity);

            return;
        }
        $batch = new ToolBatchStateDTO(
            expectedOrder: $action->expectedOrder,
            calls: $callsById,
            pendingQueue: $action->pendingQueue,
            inFlight: $action->inFlight,
            results: [],
            finalized: false,
            maxParallelism: max(1, $action->maxParallelism),
            awaitingHumanInput: [],
        );
        $this->upsert($action->runId, $action->turnNo, $action->stepId, $batch, [
            'calls' => $callRefs,
            'results' => [],
        ], $transition->identity);
    }

    /**
     * @return list<ExecuteToolCall>
     */
    public function admittedCalls(string $runId, int $turnNo, string $stepId): array
    {
        $batch = $this->load($runId, $turnNo, $stepId);
        if (null === $batch) {
            return [];
        }
        $admitted = [];
        foreach (array_keys($batch->inFlight) as $toolCallId) {
            $call = $batch->calls[$toolCallId] ?? null;
            if (!$call instanceof ExecuteToolCall || isset($batch->results[$toolCallId])) {
                continue;
            }
            $state = $this->connection->fetchOne(
                'SELECT state FROM execution_operation WHERE effect_id = ?',
                [$this->lookupCallEffectId($call)],
            );
            // Live/ready/terminal siblings stay owned by their claim/result. Only
            // Prepared permissions need activation; Armed may be republished.
            if (\in_array($state, ['Prepared', 'Armed'], true)) {
                $admitted[] = $call;
            }
        }

        return $admitted;
    }


    /**
     * @param array{calls: array<string, array<string, int|string>>, results: array<string, array<string, int|string>>} $refs
     */
    private function upsert(string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $batch, array $refs, string $appliedTransition): void
    {
        $inFlight = array_keys(array_filter($batch->inFlight, static fn (mixed $value): bool => true === $value));
        $params = [
            'run_id' => $runId,
            'turn_no' => $turnNo,
            'step_id' => $stepId,
            'max_parallelism' => $batch->maxParallelism,
            'finalized' => $batch->finalized ? 1 : 0,
            'expected_order_json' => $this->jsonEncode($batch->expectedOrder),
            'pending_queue_json' => $this->jsonEncode(array_values($batch->pendingQueue)),
            'in_flight_json' => $this->jsonEncode($inFlight),
            'awaiting_human_input_json' => $this->jsonEncode($batch->awaitingHumanInput),
            'calls_json' => $this->jsonEncode($refs['calls']),
            'results_json' => $this->jsonEncode($refs['results']),
            'applied_transition' => $appliedTransition,
        ];
        $existing = $this->connection->fetchOne(
            'SELECT 1 FROM tool_batch_schedule WHERE run_id = ? AND turn_no = ? AND step_id = ?',
            [$runId, $turnNo, $stepId],
        );
        if (false === $existing) {
            $this->connection->insert('tool_batch_schedule', $params);

            return;
        }
        $this->connection->update('tool_batch_schedule', $params, [
            'run_id' => $runId,
            'turn_no' => $turnNo,
            'step_id' => $stepId,
        ]);
    }

    /** @param array<string, mixed> $record */
    private function hydrate(array $record): ToolBatchStateDTO
    {
        /** @var array<string, int> $expectedOrder */
        $expectedOrder = $this->jsonDecodeObject($record['expected_order_json']);
        /** @var list<string> $pending */
        $pending = $this->jsonDecodeList($record['pending_queue_json']);
        /** @var list<string> $inFlightIds */
        $inFlightIds = $this->jsonDecodeList($record['in_flight_json']);
        $inFlight = [];
        foreach ($inFlightIds as $id) {
            $inFlight[$id] = true;
        }
        /** @var array<string, string> $awaiting */
        $awaiting = $this->jsonDecodeObject($record['awaiting_human_input_json']);
        $callRefs = $this->jsonDecodeObject($record['calls_json']);
        $resultRefs = $this->jsonDecodeObject($record['results_json']);
        $calls = [];
        foreach ($callRefs as $toolCallId => $ref) {
            if (!\is_array($ref)) {
                throw new \RuntimeException('Tool batch call reference is invalid.');
            }
            $calls[(string) $toolCallId] = $this->resolveCall((string) $record['run_id'], $ref);
        }
        $results = [];
        foreach ($resultRefs as $toolCallId => $ref) {
            if (!\is_array($ref)) {
                throw new \RuntimeException('Tool batch result reference is invalid.');
            }
            $resolved = $this->resolveResult((string) $record['run_id'], $ref);
            $results[(string) $toolCallId] = $resolved;
        }

        return new ToolBatchStateDTO(
            expectedOrder: $expectedOrder,
            calls: $calls,
            pendingQueue: $pending,
            inFlight: $inFlight,
            results: $results,
            finalized: (bool) $record['finalized'],
            maxParallelism: max(1, (int) $record['max_parallelism']),
            awaitingHumanInput: $awaiting,
        );
    }

    /**
     * @return array{calls: array<string, array<string, int|string>>, results: array<string, array<string, int|string>>}
     */
    private function referencesFromBatch(string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $batch): array
    {
        $callRefs = [];
        foreach ($batch->calls as $toolCallId => $call) {
            $callRefs[$toolCallId] = [
                'attempt' => $call->attempt(),
                'idempotency_key' => $call->idempotencyKey(),
                'effect_id' => $this->lookupCallEffectId($call),
                'request_hash' => $this->lookupCallRequestHash($call),
            ];
        }
        $resultRefs = [];
        foreach ($batch->results as $toolCallId => $result) {
            $resultRefs[$toolCallId] = $this->resultReference($result);
        }

        return ['calls' => $callRefs, 'results' => $resultRefs];
    }

    /** @return array<string, int|string> */
    private function callReference(ExecuteToolCall $call, VerifiedTransitionDTO $transition): array
    {
        return [
            'attempt' => $call->attempt(),
            'idempotency_key' => $call->idempotencyKey(),
            'effect_id' => $this->lookupCallEffectId($call),
            'request_hash' => $this->lookupCallRequestHash($call),
        ];
    }

    /** @return array<string, int|string> */
    private function resultReference(ToolCallResult $result): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT effect_id, claim_token, result_hash, result_bytes FROM execution_operation WHERE run_id = ? AND turn_no = ? AND step_id = ? AND logical_tool_call_id = ? AND attempt = ? AND idempotency_key = ? AND request_type = ? AND state IN ('ResultReady', 'Consumed', 'Stale') ORDER BY effect_id LIMIT 1",
            [$result->runId(), $result->turnNo(), $result->stepId(), $result->toolCallId, $result->attempt(), $result->idempotencyKey(), ExecuteToolCall::class],
        );
        if (false === $row || !\is_string($row['effect_id']) || !\is_string($row['claim_token']) || !\is_string($row['result_hash'])) {
            throw new \RuntimeException('Prepared batch result has no durable ledger reference.');
        }

        return [
            'attempt' => $result->attempt(),
            'idempotency_key' => $result->idempotencyKey(),
            'effect_id' => $row['effect_id'],
            'claim_token' => $row['claim_token'],
            'result_hash' => $row['result_hash'],
            'bytes' => (int) $row['result_bytes'],
            'turn_no' => $result->turnNo(),
            'step_id' => $result->stepId(),
        ];
    }

    /** @param array<string, mixed> $ref */
    private function resolveCall(string $runId, array $ref): ExecuteToolCall
    {
        $effectId = (string) ($ref['effect_id'] ?? '');
        $requestHash = (string) ($ref['request_hash'] ?? '');
        $attempt = (int) ($ref['attempt'] ?? 0);
        $key = (string) ($ref['idempotency_key'] ?? '');
        $row = $this->connection->fetchAssociative('SELECT * FROM execution_operation WHERE effect_id = ?', [$effectId]);
        if (false === $row || $row['run_id'] !== $runId || (int) $row['attempt'] !== $attempt || $row['idempotency_key'] !== $key || $row['request_hash'] !== $requestHash) {
            throw new \RuntimeException('Tool batch call reference is missing from the invocation ledger.');
        }
        $request = $this->operations->peekRequest(new ExecutionRequest(
            $row['run_id'],
            (int) $row['turn_no'],
            $row['step_id'],
            (int) $row['attempt'],
            $row['idempotency_key'],
            $row['effect_id'],
            $row['request_type'],
            $row['request_hash'],
            (int) $row['request_bytes'],
        ));
        if (!$request instanceof ExecuteToolCall) {
            throw new \RuntimeException('Tool batch call reference resolved a non-tool invocation.');
        }

        return $request;
    }

    /** @param array<string, mixed> $ref */
    private function resolveResult(string $runId, array $ref): ToolCallResult
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM execution_operation WHERE effect_id = ?', [(string) ($ref['effect_id'] ?? '')]);
        if (false === $row || $row['run_id'] !== $runId) {
            throw new \RuntimeException('Tool batch result reference is missing from the invocation ledger.');
        }
        $result = $this->operations->resolveResult(new DurableExecutionResult(
            $runId,
            (int) $row['turn_no'],
            (string) $row['step_id'],
            (int) $row['attempt'],
            (string) $row['idempotency_key'],
            (string) $row['effect_id'],
            (string) $row['claim_token'],
            (string) $row['result_hash'],
            (int) $row['result_bytes'],
            ToolCallResult::class,
        ));
        if (!$result instanceof ToolCallResult) {
            throw new \RuntimeException('Tool batch result reference resolved a non-tool result.');
        }

        return $result;
    }

    private function lookupCallEffectId(ExecuteToolCall $call): string
    {
        $row = $this->connection->fetchAssociative(
            'SELECT effect_id FROM execution_operation WHERE run_id = ? AND turn_no = ? AND step_id = ? AND logical_tool_call_id = ? AND attempt = ? AND idempotency_key = ? AND request_type = ? ORDER BY effect_id DESC LIMIT 1',
            [$call->runId(), $call->turnNo(), $call->stepId(), $call->toolCallId, $call->attempt(), $call->idempotencyKey(), ExecuteToolCall::class],
        );
        if (false === $row) {
            throw new \RuntimeException('Tool batch call has no durable ledger reference.');
        }

        return (string) $row['effect_id'];
    }

    private function lookupCallRequestHash(ExecuteToolCall $call): string
    {
        $row = $this->connection->fetchAssociative(
            'SELECT request_hash FROM execution_operation WHERE run_id = ? AND turn_no = ? AND step_id = ? AND logical_tool_call_id = ? AND attempt = ? AND idempotency_key = ? AND request_type = ? ORDER BY effect_id DESC LIMIT 1',
            [$call->runId(), $call->turnNo(), $call->stepId(), $call->toolCallId, $call->attempt(), $call->idempotencyKey(), ExecuteToolCall::class],
        );
        if (false === $row) {
            throw new \RuntimeException('Tool batch call has no durable ledger reference.');
        }

        return (string) $row['request_hash'];
    }

    private function appliedTransition(string $runId, int $turnNo, string $stepId): string
    {
        $value = $this->connection->fetchOne(
            'SELECT applied_transition FROM tool_batch_schedule WHERE run_id = ? AND turn_no = ? AND step_id = ?',
            [$runId, $turnNo, $stepId],
        );

        return false === $value ? '' : (string) $value;
    }

    /** @param array<string, mixed> $record */
    private function retainSchedulingEvidence(array $record): bool
    {
        $awaiting = $this->jsonDecodeObject($record['awaiting_human_input_json']);
        $pending = $this->jsonDecodeList($record['pending_queue_json']);
        $inFlight = $this->jsonDecodeList($record['in_flight_json']);
        if (!(bool) $record['finalized'] || [] !== $awaiting || [] !== $pending || [] !== $inFlight) {
            $this->logger->info('tool_batch.scheduling_evidence_retained', [
                'component' => 'doctrine_tool_batch_store',
                'event_type' => 'scheduling_evidence_retained',
                'run_id' => $record['run_id'] ?? null,
                'turn_no' => $record['turn_no'] ?? null,
                'step_id' => $record['step_id'] ?? null,
                'finalized' => (bool) $record['finalized'],
                'awaiting_human_input' => \count($awaiting),
                'pending_queue' => \count($pending),
                'in_flight' => \count($inFlight),
            ]);

            return true;
        }

        return false;
    }


    /** @param array<string, mixed>|list<mixed> $value */
    private function jsonEncode(array $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function jsonDecodeObject(mixed $json): array
    {
        $decoded = json_decode((string) $json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Tool batch schedule object JSON is invalid.');
        }

        return $decoded;
    }

    /** @return list<mixed> */
    private function jsonDecodeList(mixed $json): array
    {
        $decoded = json_decode((string) $json, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException('Tool batch schedule list JSON is invalid.');
        }

        return $decoded;
    }

    private function sanitizeRunId(string $runId): void
    {
        if ('' === $runId || str_contains($runId, '/') || str_contains($runId, '\\') || str_contains($runId, "\0")) {
            throw new \RuntimeException(\sprintf('Invalid tool batch run ID: "%s".', $runId));
        }
    }
}
