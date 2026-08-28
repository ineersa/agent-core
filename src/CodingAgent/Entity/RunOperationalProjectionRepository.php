<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Contract\RunOperationalStatusDTO;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Clock\Clock;

/**
 * SQL-only storage for the disposable, payload-free run coordination projection.
 * This is deliberately not a RunState store and has no optimistic-CAS API.
 */
final readonly class RunOperationalProjectionRepository implements RunOperationalStatusReaderInterface
{
    public function __construct(
        private Connection $connection,
        private ?RunMetrics $metrics = null,
    ) {
    }

    /**
     * @param list<RunOperationalToolCallDTO>   $toolCalls
     * @param list<RunOperationalHumanInputDTO> $humanInputs
     */
    public function replaceStateToolCallsAndHumanInputs(RunOperationalProjectionDTO $projection, array $toolCalls, array $humanInputs): void
    {
        $startedAt = hrtime(true);
        $success = false;
        try {
            $this->connection->transactional(function () use ($projection, $toolCalls, $humanInputs): void {
                $this->replaceProjection($projection);
                $this->replaceToolCallRows($projection->runId, $toolCalls);
                $this->replaceHumanInputRows($projection->runId, $humanInputs);
            });
            $success = true;
        } finally {
            $this->metrics?->recordProjectionReplacement(
                $success,
                $projection->ownerSessionId === $projection->runId ? 'parent' : 'child',
                \count($toolCalls),
                \count($humanInputs),
                $this->logicalScalarBytes($projection, $toolCalls, $humanInputs),
                (hrtime(true) - $startedAt) / 1_000_000,
            );
        }
    }

    public function findOperationalStatus(string $runId): ?RunOperationalStatusDTO
    {
        $startedAt = hrtime(true);
        $miss = false;
        $error = false;
        try {
            $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT run_id, status, operation_turn_no, operation_step_id, operation_attempt, operation_key
FROM run_operational_state WHERE run_id = :run_id
SQL, ['run_id' => $runId]);
            if (false === $row) {
                $miss = true;

                return null;
            }

            $operation = null;
            if (null !== $row['operation_key']) {
                $operation = new CurrentOperationDTO(
                    (int) $row['operation_turn_no'],
                    (string) $row['operation_step_id'],
                    (int) $row['operation_attempt'],
                    (string) $row['operation_key'],
                );
            }

            return new RunOperationalStatusDTO((string) $row['run_id'], RunStatus::from((string) $row['status']), $operation);
        } catch (\Throwable $exception) {
            $error = true;

            throw $exception;
        } finally {
            $this->metrics?->recordOperationalStatusRead($miss, $error, (hrtime(true) - $startedAt) / 1_000_000);
        }
    }

    public function deleteForOwnerSession(string $ownerSessionId): int
    {
        return $this->connection->transactional(function () use ($ownerSessionId): int {
            // SQLite foreign-key enforcement is connection-configurable. Delete the
            // dependents explicitly as well as declaring ON DELETE CASCADE in DDL,
            // so owner-scoped startup cleanup is correct on every app connection.
            $runIds = 'SELECT run_id FROM run_operational_state WHERE owner_session_id = :owner_session_id';
            $this->connection->executeStatement('DELETE FROM run_operational_tool_call WHERE run_id IN ('.$runIds.')', ['owner_session_id' => $ownerSessionId]);
            $this->connection->executeStatement('DELETE FROM run_operational_human_input WHERE run_id IN ('.$runIds.')', ['owner_session_id' => $ownerSessionId]);

            return $this->connection->delete('run_operational_state', ['owner_session_id' => $ownerSessionId]);
        });
    }

    private function replaceProjection(RunOperationalProjectionDTO $projection): void
    {
        $now = Clock::get()->now()->format('Y-m-d H:i:s');
        $operation = $projection->currentOperation;
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO run_operational_state (
    run_id, owner_session_id, status, turn_no, active_step_id,
    operation_turn_no, operation_step_id, operation_attempt, operation_key,
    last_applied_advance_key, last_applied_compaction_key,
    retryable_failure, retry_attempts, last_event_sequence, transition_version,
    created_at, updated_at
) VALUES (
    :run_id, :owner_session_id, :status, :turn_no, :active_step_id,
    :operation_turn_no, :operation_step_id, :operation_attempt, :operation_key,
    :last_applied_advance_key, :last_applied_compaction_key,
    :retryable_failure, :retry_attempts, :last_event_sequence, :transition_version,
    :created_at, :updated_at
)
ON CONFLICT(run_id) DO UPDATE SET
    owner_session_id = excluded.owner_session_id,
    status = excluded.status,
    turn_no = excluded.turn_no,
    active_step_id = excluded.active_step_id,
    operation_turn_no = excluded.operation_turn_no,
    operation_step_id = excluded.operation_step_id,
    operation_attempt = excluded.operation_attempt,
    operation_key = excluded.operation_key,
    last_applied_advance_key = excluded.last_applied_advance_key,
    last_applied_compaction_key = excluded.last_applied_compaction_key,
    retryable_failure = excluded.retryable_failure,
    retry_attempts = excluded.retry_attempts,
    last_event_sequence = excluded.last_event_sequence,
    transition_version = excluded.transition_version,
    updated_at = excluded.updated_at
SQL, [
            'run_id' => $projection->runId,
            'owner_session_id' => $projection->ownerSessionId,
            'status' => $projection->status->value,
            'turn_no' => $projection->turnNo,
            'active_step_id' => $projection->activeStepId,
            'operation_turn_no' => $operation?->turnNo,
            'operation_step_id' => $operation?->stepId,
            'operation_attempt' => $operation?->attempt,
            'operation_key' => $operation?->idempotencyKey,
            'last_applied_advance_key' => $projection->lastAppliedAdvanceKey,
            'last_applied_compaction_key' => $projection->lastAppliedCompactionKey,
            'retryable_failure' => $projection->retryableFailure ? 1 : 0,
            'retry_attempts' => $projection->retryAttempts,
            'last_event_sequence' => $projection->lastEventSequence,
            'transition_version' => $projection->transitionVersion,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param list<RunOperationalToolCallDTO> $toolCalls */
    private function replaceToolCallRows(string $runId, array $toolCalls): void
    {
        $this->connection->delete('run_operational_tool_call', ['run_id' => $runId]);
        $now = Clock::get()->now()->format('Y-m-d H:i:s');
        foreach ($toolCalls as $toolCall) {
            $this->connection->insert('run_operational_tool_call', [
                'run_id' => $runId, 'batch_id' => $toolCall->batchId, 'tool_call_id' => $toolCall->toolCallId,
                'order_index' => $toolCall->orderIndex, 'status' => $toolCall->status, 'attempt' => $toolCall->attempt,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param list<RunOperationalToolCallDTO>   $toolCalls
     * @param list<RunOperationalHumanInputDTO> $humanInputs
     */
    private function logicalScalarBytes(RunOperationalProjectionDTO $projection, array $toolCalls, array $humanInputs): int
    {
        $values = [
            $projection->runId, $projection->ownerSessionId, $projection->status->value, $projection->turnNo,
            $projection->activeStepId, $projection->currentOperation?->turnNo, $projection->currentOperation?->stepId,
            $projection->currentOperation?->attempt, $projection->currentOperation?->idempotencyKey,
            $projection->lastAppliedAdvanceKey, $projection->lastAppliedCompactionKey, $projection->retryableFailure,
            $projection->retryAttempts, $projection->lastEventSequence, $projection->transitionVersion,
        ];
        foreach ($toolCalls as $toolCall) {
            array_push($values, $projection->runId, $toolCall->batchId, $toolCall->toolCallId, $toolCall->orderIndex, $toolCall->status, $toolCall->attempt);
        }
        foreach ($humanInputs as $humanInput) {
            array_push($values, $projection->runId, $humanInput->questionId, $humanInput->orderIndex, $humanInput->continuationKind, $humanInput->toolCallId, $humanInput->status);
        }

        // Every replaced row persists created_at and updated_at in SQLite's fixed
        // YYYY-MM-DD HH:MM:SS representation. Logical scalar bytes account for
        // scalar values, not SQLite page allocation or indexes.
        $timestampBytes = 2 * 19 * (1 + \count($toolCalls) + \count($humanInputs));

        return $timestampBytes + array_sum(array_map(static fn (mixed $value): int => \is_string($value) ? \strlen($value) : (null === $value ? 0 : \strlen((string) (int) $value)), $values));
    }

    /** @param list<RunOperationalHumanInputDTO> $humanInputs */
    private function replaceHumanInputRows(string $runId, array $humanInputs): void
    {
        $this->connection->delete('run_operational_human_input', ['run_id' => $runId]);
        $now = Clock::get()->now()->format('Y-m-d H:i:s');
        foreach ($humanInputs as $humanInput) {
            $this->connection->insert('run_operational_human_input', [
                'run_id' => $runId, 'question_id' => $humanInput->questionId, 'order_index' => $humanInput->orderIndex,
                'continuation_kind' => $humanInput->continuationKind, 'tool_call_id' => $humanInput->toolCallId,
                'status' => $humanInput->status, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
