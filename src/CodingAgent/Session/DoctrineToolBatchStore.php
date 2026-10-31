<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolBatchScheduleDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Scheduling state and bounded references to immutable invocation/result data.
 * Result deltas neither hydrate siblings nor rewrite their files or index rows.
 */
final readonly class DoctrineToolBatchStore implements ToolBatchStoreInterface
{
    public function __construct(
        private Connection $connection,
        private ToolPayloadStoreInterface $payloads,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
        private RunLockManager $locks,
        private LoggerInterface $logger,
    ) {
    }

    public function load(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO
    {
        $schedule = $this->loadSchedule($runId, $turnNo, $stepId);
        if (null === $schedule) {
            return null;
        }
        $store = $this->withRunPayloads($runId);
        $calls = $store->loadCalls($runId, $turnNo, $stepId);
        $results = $store->loadResults($runId, $turnNo, $stepId);

        return new ToolBatchStateDTO($schedule->expectedOrder, $calls, $schedule->pendingQueue, $schedule->inFlight, $results, $schedule->finalized, $schedule->maxParallelism, $schedule->awaitingHumanInput);
    }

    public function loadSchedule(string $runId, int $turnNo, string $stepId): ?ToolBatchScheduleDTO
    {
        $record = $this->record($runId, $turnNo, $stepId);

        return null === $record ? null : $this->decodeSchedule($record);
    }

    public function loadCall(string $runId, int $turnNo, string $stepId, string $toolCallId): ?ExecuteToolCall
    {
        $this->sanitizeRunId($runId);
        $row = $this->connection->fetchAssociative('SELECT * FROM tool_batch_call WHERE run_id = ? AND turn_no = ? AND step_id = ? AND tool_call_id = ?', [$runId, $turnNo, $stepId, $toolCallId]);

        return false === $row ? null : $this->decodeCall($row);
    }

    public function loadCallReference(string $runId, int $turnNo, string $stepId, string $toolCallId): ?ToolPayloadReferenceDTO
    {
        $this->sanitizeRunId($runId);
        $row = $this->connection->fetchAssociative('SELECT * FROM tool_batch_call WHERE run_id = ? AND turn_no = ? AND step_id = ? AND tool_call_id = ?', [$runId, $turnNo, $stepId, $toolCallId]);

        return false === $row ? null : $this->decodeReference($row, 'call');
    }

    public function loadResult(string $runId, int $turnNo, string $stepId, string $toolCallId): ?ToolCallResult
    {
        $this->sanitizeRunId($runId);
        $row = $this->connection->fetchAssociative('SELECT * FROM tool_batch_result WHERE run_id = ? AND turn_no = ? AND step_id = ? AND tool_call_id = ?', [$runId, $turnNo, $stepId, $toolCallId]);

        return false === $row ? null : $this->decodeResult($row);
    }

    public function loadResults(string $runId, int $turnNo, string $stepId): array
    {
        $record = $this->record($runId, $turnNo, $stepId);
        if (null === $record) {
            return [];
        }
        $order = $this->decodeJson($record['expected_order_json']);
        $store = $this->withRunPayloads($runId);
        $results = [];
        foreach ($this->connection->iterateAssociative('SELECT * FROM tool_batch_result WHERE run_id = ? AND turn_no = ? AND step_id = ? ORDER BY order_index, rowid', [$runId, $turnNo, $stepId]) as $row) {
            $result = $store->decodeResult($row);
            if (($order[$result->toolCallId] ?? null) !== $result->orderIndex) {
                throw new \RuntimeException('Stored tool batch result membership is invalid.');
            }
            $results[$result->toolCallId] = $result;
        }

        return $results;
    }

    public function prepareChanges(array $actions, VerifiedTransitionDTO $transition): \Closure
    {
        $runId = null;
        foreach ($actions as $action) {
            $this->verifyAction($action, $transition);
            $runId = $action->runId;
        }
        $store = null === $runId ? $this : $this->withRunPayloads($runId);

        // Reuse one short publication guard across every touched body. The
        // returned SQL action executes later, after this storage lock releases.
        return null === $runId ? $store->prepareVerifiedChanges($actions, $transition)
            : $store->payloads->withStorage($runId, static fn (): \Closure => $store->prepareVerifiedChanges($actions, $transition));
    }

    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        $this->locks->synchronized($runId, fn () => $this->deleteSettled($runId, $turnNo, $stepId));
    }

    public function deleteAllForRun(string $runId): void
    {
        $this->locks->synchronized($runId, fn () => $this->deleteSettled($runId));
    }

    public function hasUnresolvedExecution(string $runId): bool
    {
        $this->sanitizeRunId($runId);

        return (bool) $this->connection->fetchOne("SELECT CASE WHEN EXISTS (SELECT 1 FROM tool_deferred_completion WHERE run_id = ? AND status = 'pending') THEN 1 ELSE EXISTS (SELECT 1 FROM tool_batch b WHERE b.run_id = ? AND (b.finalized = 0 OR b.awaiting_human_input_json <> '[]' OR b.pending_queue_json <> '[]' OR b.in_flight_json <> '[]')) END", [$runId, $runId]);
    }

    public function findUnresolvedToolCallIds(string $runId, array $toolCallIds): array
    {
        $this->sanitizeRunId($runId);
        $unresolved = [];
        // Two IN lists plus run IDs use at most 802 bound parameters per chunk,
        // below SQLite's traditional 999-parameter limit.
        foreach (array_chunk(array_values(array_unique($toolCallIds)), 400) as $ids) {
            $sql = "SELECT tool_call_id FROM tool_deferred_completion WHERE run_id = ? AND status = 'pending' AND tool_call_id IN (?)
                UNION
                SELECT c.tool_call_id FROM tool_batch_call c JOIN tool_batch b ON b.run_id = c.run_id AND b.turn_no = c.turn_no AND b.step_id = c.step_id
                WHERE c.run_id = ? AND c.tool_call_id IN (?) AND (b.finalized = 0 OR b.awaiting_human_input_json <> '[]' OR b.pending_queue_json <> '[]' OR b.in_flight_json <> '[]')";
            foreach ($this->connection->fetchFirstColumn($sql, [$runId, $ids, $runId, $ids], [ParameterType::STRING, ArrayParameterType::STRING, ParameterType::STRING, ArrayParameterType::STRING]) as $id) {
                $unresolved[] = (string) $id;
            }
        }

        return $unresolved;
    }

    /** @param list<RegisterToolBatchDTO|FinalizeToolBatchDTO> $actions */
    private function prepareVerifiedChanges(array $actions, VerifiedTransitionDTO $transition): \Closure
    {
        $records = [];
        $schedules = [];
        $headers = [];
        $callWrites = [];
        $resultWrites = [];
        $calls = [];
        $results = [];
        $validatedBodies = [];
        $deferredSettlements = [];
        foreach ($actions as $action) {
            $key = $this->encodeJson([$action->runId, $action->turnNo, $action->stepId]);
            if (!\array_key_exists($key, $records)) {
                $records[$key] = $this->record($action->runId, $action->turnNo, $action->stepId);
            }
            // Keep the durable identity separate from prepared scheduling state:
            // later actions must not mistake this uncommitted plan for redelivery.
            $record = $records[$key];
            if (null !== $record && $record['applied_transition'] === $transition->identity) {
                continue;
            }
            if (!\array_key_exists($key, $schedules)) {
                $schedules[$key] = null === $record ? null : $this->decodeSchedule($record);
            }
            $schedule = $schedules[$key];
            if (null !== $record && !isset($validatedBodies[$key]) && ($action instanceof RegisterToolBatchDTO || null === $action->result)) {
                // Registration, answers and cancellation still validate retained
                // file evidence, including unchanged siblings. Ordinary result
                // deltas leave those rows untouched; completion hydrates them once.
                $calls[$key] = array_replace($this->loadCalls($action->runId, $action->turnNo, $action->stepId), $calls[$key] ?? []);
                $results[$key] = array_replace($this->loadResults($action->runId, $action->turnNo, $action->stepId), $results[$key] ?? []);
                $validatedBodies[$key] = true;
            }
            if ($action instanceof RegisterToolBatchDTO) {
                $registered = [];
                $modes = [];
                foreach ($action->effects as $call) {
                    $this->validateMessageIdentity($call, $action->runId, $action->turnNo, $action->stepId, $call->toolCallId, $action->expectedOrder[$call->toolCallId] ?? -1);
                    $registered[$call->toolCallId] = $call;
                    $modes[$call->toolCallId] = $this->mode($call);
                }
                if (\count($registered) !== \count($action->expectedOrder) || [] !== array_diff_key($registered, $action->expectedOrder)) {
                    throw new \LogicException('Prepared batch invocation membership is invalid.');
                }
                if (null !== $schedule) {
                    $stored = $calls[$key] ?? $this->loadCalls($action->runId, $action->turnNo, $action->stepId);
                    if ($schedule->expectedOrder !== $action->expectedOrder || serialize($stored) !== serialize($registered)) {
                        throw new \LogicException('Conflicting prepared tool batch registration.');
                    }
                    // Registration redelivery never resets collected siblings or admission.
                    $calls[$key] = $stored;
                } else {
                    $schedule = new ToolBatchScheduleDTO($action->expectedOrder, $modes, $action->pendingQueue, $action->inFlight, [], false, max(1, $action->maxParallelism));
                    $calls[$key] = $registered;
                    foreach ($registered as $id => $call) {
                        $callWrites[$key][$id] = $this->encodeCall($call);
                    }
                }
            } else {
                if (null === $schedule) {
                    throw new \RuntimeException('Prepared batch evidence is missing.');
                }
                if (null !== $action->result) {
                    $result = $action->result;
                    $this->validateMessageIdentity($result, $action->runId, $action->turnNo, $action->stepId, $result->toolCallId, $schedule->expectedOrder[$result->toolCallId] ?? -1);
                    if (isset($schedule->completedIds[$result->toolCallId])) {
                        $stored = $results[$key][$result->toolCallId] ?? $this->loadResult($action->runId, $action->turnNo, $action->stepId, $result->toolCallId);
                        if (null === $stored || !$this->equivalentResults($stored, $result)) {
                            throw new \LogicException('Conflicting prepared tool batch result.');
                        }
                    } else {
                        $resultWrites[$key][$result->toolCallId] = $this->encodeResult($result);
                        $schedule->completedIds[$result->toolCallId] = true;
                    }
                    $results[$key][$result->toolCallId] = $result;
                    $deferredSettlements[] = [$action->runId, $action->turnNo, $action->stepId, $result->toolCallId];
                }
                if (null !== $action->revisedCallId) {
                    $id = $action->revisedCallId;
                    $call = $calls[$key][$id] ?? $this->loadCall($action->runId, $action->turnNo, $action->stepId, $id);
                    if (null === $call || !isset($schedule->expectedOrder[$id])) {
                        throw new \RuntimeException('Prepared human answer has no stored call.');
                    }
                    $call = null === $action->answer ? $call->withHumanInputAnswer(null) : $call->withAuthorizedHumanAnswer($action->answer);
                    $calls[$key][$id] = $call;
                    $callWrites[$key][$id] = $this->encodeCall($call);
                }
                $schedule->pendingQueue = $action->pendingQueue;
                $schedule->inFlight = $action->inFlight;
                $schedule->awaitingHumanInput = $action->awaitingHumanInput;
                $schedule->finalized = $action->finalized;
            }
            $this->validateSchedule($schedule);
            $schedules[$key] = $schedule;
            // Only touched bodies are encoded, before the metadata transaction.
            $headers[$key] = $this->encodeHeader($action->runId, $action->turnNo, $action->stepId, $schedule, $transition->identity);
        }

        return function () use ($headers, $records, $callWrites, $resultWrites, $deferredSettlements): void {
            foreach ($headers as $batchKey => $row) {
                $key = ['run_id' => $row['run_id'], 'turn_no' => $row['turn_no'], 'step_id' => $row['step_id']];
                $changes = array_diff_key($row, $key);
                foreach ($changes as $column => $value) {
                    if (null !== $records[$batchKey] && $records[$batchKey][$column] === $value) {
                        unset($changes[$column]);
                    }
                }
                // Preparation and apply share the owner lock. Captured absence
                // means insert; a vanished expected row is not recoverable here.
                if (null === $records[$batchKey]) {
                    $this->connection->insert('tool_batch', $row);
                } elseif (0 === (int) $this->connection->update('tool_batch', $changes, $key)) {
                    throw new \RuntimeException('Prepared tool batch header disappeared before apply.');
                }
                foreach ($callWrites[$batchKey] ?? [] as $callRow) {
                    $callKey = $key + ['tool_call_id' => $callRow['tool_call_id']];
                    if (null === $records[$batchKey]) {
                        $this->connection->insert('tool_batch_call', $callRow);
                    } elseif (0 === (int) $this->connection->update('tool_batch_call', array_diff_key($callRow, $callKey), $callKey)) {
                        throw new \RuntimeException('Prepared tool batch call disappeared before apply.');
                    }
                }
                foreach ($resultWrites[$batchKey] ?? [] as $resultRow) {
                    $this->connection->insert('tool_batch_result', $resultRow);
                }
            }
            foreach ($deferredSettlements as [$runId, $turnNo, $stepId, $toolCallId]) {
                // Early code_mode registrations stay pending until this
                // verified batch-result admission commits.
                $this->connection->executeStatement(
                    "UPDATE tool_deferred_completion SET status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE run_id = ? AND turn_no = ? AND step_id = ? AND tool_call_id = ? AND status = 'pending' AND backgrounded_at IS NULL",
                    [$runId, $turnNo, $stepId, $toolCallId],
                );
            }
        };
    }

    private function deleteSettled(string $runId, ?int $turnNo = null, ?string $stepId = null): void
    {
        $this->sanitizeRunId($runId);
        $where = "b.run_id = ? AND b.finalized = 1 AND b.awaiting_human_input_json = '[]' AND b.pending_queue_json = '[]' AND b.in_flight_json = '[]' AND NOT EXISTS (SELECT 1 FROM tool_deferred_completion d WHERE d.run_id = b.run_id AND d.turn_no = b.turn_no AND d.step_id = b.step_id AND d.status = 'pending')";
        $outerWhere = 'run_id = ?';
        $params = [$runId];
        if (null !== $turnNo) {
            $where .= ' AND b.turn_no = ? AND b.step_id = ?';
            $outerWhere .= ' AND turn_no = ? AND step_id = ?';
            $params[] = $turnNo;
            $params[] = $stepId;
        }
        $callIds = $this->connection->fetchFirstColumn("SELECT c.tool_call_id FROM tool_batch_call c JOIN tool_batch b ON b.run_id = c.run_id AND b.turn_no = c.turn_no AND b.step_id = c.step_id WHERE $where", $params);
        // Commit reference deletion before touching files, while retaining the
        // same run critical section through the survivor check and file cleanup.
        $this->connection->transactional(function () use ($where, $outerWhere, $params): void {
            foreach (['tool_batch_result', 'tool_batch_call'] as $table) {
                $this->connection->executeStatement("DELETE FROM $table WHERE $outerWhere AND EXISTS (SELECT 1 FROM tool_batch b WHERE b.run_id = $table.run_id AND b.turn_no = $table.turn_no AND b.step_id = $table.step_id AND $where)", array_merge($params, $params));
            }
            $this->connection->executeStatement("DELETE FROM tool_batch AS b WHERE $where", $params);
        });
        if ([] === $callIds) {
            return;
        }
        // One run-scoped read protects shared IDs in surviving batches. The
        // owner lock prevents new references until these deletions finish.
        $survivors = array_fill_keys($this->connection->fetchFirstColumn('SELECT DISTINCT tool_call_id FROM tool_batch_call WHERE run_id = ?', [$runId]), true);
        $retained = array_fill_keys($this->connection->fetchFirstColumn('SELECT DISTINCT tool_call_id FROM tool_deferred_completion WHERE run_id = ? AND accepted_result_reference_json IS NOT NULL', [$runId]), true);
        $safeIds = array_diff(array_unique($callIds), array_keys($survivors), array_keys($retained));
        if ([] === $safeIds) {
            return;
        }
        $payloads = $this->payloads->forRun($runId);
        foreach ($safeIds as $id) {
            try {
                $payloads->deleteCall($runId, $id);
            } catch (\Throwable $exception) {
                $this->logger->warning('tool_payload.cleanup_failed', ['run_id' => $runId, 'session_id' => $runId, 'tool_call_id' => $id, 'component' => 'tool_batch', 'event_type' => 'tool_payload.cleanup_failed', 'exception_class' => $exception::class]);
            }
        }
    }

    /** @return array<string, ExecuteToolCall> */
    private function loadCalls(string $runId, int $turnNo, string $stepId): array
    {
        $store = $this->withRunPayloads($runId);
        $calls = [];
        foreach ($this->connection->iterateAssociative('SELECT * FROM tool_batch_call WHERE run_id = ? AND turn_no = ? AND step_id = ? ORDER BY rowid', [$runId, $turnNo, $stepId]) as $row) {
            $call = $store->decodeCall($row);
            $calls[$call->toolCallId] = $call;
        }

        return $calls;
    }

    private function withRunPayloads(string $runId): self
    {
        return new self($this->connection, $this->payloads->forRun($runId), $this->serializer, $this->validator, $this->locks, $this->logger);
    }

    /** @param array<string, mixed> $record */
    private function decodeSchedule(array $record): ToolBatchScheduleDTO
    {
        $params = [$record['run_id'], (int) $record['turn_no'], $record['step_id']];
        /** @var array<string, int> $order */
        $order = $this->decodeJson($record['expected_order_json']);
        $modes = [];
        foreach ($this->connection->fetchAllAssociative('SELECT tool_call_id, order_index, mode FROM tool_batch_call WHERE run_id = ? AND turn_no = ? AND step_id = ? ORDER BY order_index, rowid', $params) as $row) {
            $id = (string) $row['tool_call_id'];
            $mode = ToolExecutionMode::tryFrom((string) $row['mode']);
            if (($order[$id] ?? null) !== (int) $row['order_index'] || null === $mode) {
                throw new \RuntimeException('Stored tool batch call membership is invalid.');
            }
            $modes[$id] = $mode->value;
        }
        if (\count($modes) !== \count($order)) {
            throw new \RuntimeException('Stored tool batch invocation is missing.');
        }
        $completed = [];
        foreach ($this->connection->fetchAllAssociative('SELECT tool_call_id, order_index FROM tool_batch_result WHERE run_id = ? AND turn_no = ? AND step_id = ?', $params) as $row) {
            $id = (string) $row['tool_call_id'];
            if (($order[$id] ?? null) !== (int) $row['order_index']) {
                throw new \RuntimeException('Stored tool batch result membership is invalid.');
            }
            $completed[$id] = true;
        }
        /** @var list<string> $pending */
        $pending = $this->decodeJson($record['pending_queue_json']);
        /** @var list<string> $inFlight */
        $inFlight = $this->decodeJson($record['in_flight_json']);
        /** @var array<string, string> $awaiting */
        $awaiting = $this->decodeJson($record['awaiting_human_input_json']);
        $schedule = new ToolBatchScheduleDTO(
            $order, $modes, $pending, array_fill_keys($inFlight, true), $completed,
            (bool) $record['finalized'], max(1, (int) $record['max_parallelism']), $awaiting,
        );
        $this->validateSchedule($schedule);

        return $schedule;
    }

    private function validateSchedule(ToolBatchScheduleDTO $schedule): void
    {
        foreach ($schedule->expectedOrder as $id => $index) {
            if (!\is_int($index) || !isset($schedule->callModes[$id])) {
                throw new \RuntimeException('Stored tool batch order is invalid.');
            }
        }
        foreach ([$schedule->pendingQueue, array_keys($schedule->inFlight), array_keys($schedule->awaitingHumanInput), array_keys($schedule->completedIds)] as $ids) {
            foreach ($ids as $id) {
                if (!\array_key_exists($id, $schedule->expectedOrder)) {
                    throw new \RuntimeException('Stored tool batch scheduling membership is invalid.');
                }
            }
        }
    }

    private function verifyAction(RegisterToolBatchDTO|FinalizeToolBatchDTO $action, VerifiedTransitionDTO $transition): void
    {
        if (($transition->work['run_id'] ?? null) !== $action->runId) {
            throw new \RuntimeException('Prepared batch requires matching verified transition.');
        }
        foreach ([...($transition->work['actions'] ?? []), ...($transition->work['after_turn_actions'] ?? [])] as $expected) {
            if (serialize($expected) === serialize($action)) {
                return;
            }
        }
        throw new \RuntimeException('Prepared batch differs from captured transition work.');
    }

    /** @return array<string, mixed>|null */
    private function record(string $runId, int $turnNo, string $stepId): ?array
    {
        $this->sanitizeRunId($runId);
        $row = $this->connection->fetchAssociative('SELECT * FROM tool_batch WHERE run_id = ? AND turn_no = ? AND step_id = ?', [$runId, $turnNo, $stepId]);

        return false === $row ? null : $row;
    }

    /** @return array<string, mixed> */
    private function encodeHeader(string $runId, int $turnNo, string $stepId, ToolBatchScheduleDTO $schedule, string $identity): array
    {
        return [
            'run_id' => $runId,
            'turn_no' => $turnNo,
            'step_id' => $stepId,
            'max_parallelism' => $schedule->maxParallelism,
            'finalized' => $schedule->finalized ? 1 : 0,
            'expected_order_json' => $this->encodeJson($schedule->expectedOrder),
            'pending_queue_json' => $this->encodeJson($schedule->pendingQueue),
            'in_flight_json' => $this->encodeJson(array_keys($schedule->inFlight)),
            'awaiting_human_input_json' => $this->encodeJson($schedule->awaitingHumanInput),
            'applied_transition' => $identity,
        ];
    }

    /** @return array<string, mixed> */
    private function encodeCall(ExecuteToolCall $call): array
    {
        return $this->messageRow($call) + ['mode' => $this->mode($call)];
    }

    /** @return array<string, mixed> */
    private function encodeResult(ToolCallResult $result): array
    {
        return $this->messageRow($result);
    }

    /** @return array<string, mixed> */
    private function messageRow(ExecuteToolCall|ToolCallResult $message): array
    {
        return [
            'run_id' => $message->runId(),
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
            'tool_call_id' => $message->toolCallId,
            'order_index' => $message->orderIndex,
            'body_reference_json' => $this->serializer->serialize($this->payloads->publish($message), 'json'),
        ];
    }

    /** @param array<string, mixed> $row */
    private function decodeCall(array $row): ExecuteToolCall
    {
        $message = $this->payloads->read($this->decodeReference($row, 'call'));
        if (!$message instanceof ExecuteToolCall || $this->mode($message) !== $row['mode']) {
            throw new \RuntimeException('Stored tool batch call type or mode is invalid.');
        }
        $this->validateMessageIdentity($message, $row['run_id'], (int) $row['turn_no'], $row['step_id'], $row['tool_call_id'], (int) $row['order_index']);

        return $message;
    }

    /** @param array<string, mixed> $row */
    private function decodeResult(array $row): ToolCallResult
    {
        $message = $this->payloads->read($this->decodeReference($row, 'result'));
        if (!$message instanceof ToolCallResult) {
            throw new \RuntimeException('Stored tool batch result type is invalid.');
        }
        $this->validateMessageIdentity($message, $row['run_id'], (int) $row['turn_no'], $row['step_id'], $row['tool_call_id'], (int) $row['order_index']);

        return $message;
    }

    private function validateMessageIdentity(ExecuteToolCall|ToolCallResult $message, string $runId, int $turnNo, string $stepId, string $toolCallId, int $orderIndex): void
    {
        if ($message->runId() !== $runId || $message->turnNo() !== $turnNo || $message->stepId() !== $stepId || $message->toolCallId !== $toolCallId || $message->orderIndex !== $orderIndex) {
            throw new \RuntimeException('Stored tool batch message identity is invalid.');
        }
    }

    private function mode(ExecuteToolCall $call): string
    {
        return (ToolExecutionMode::tryFrom((string) ($call->mode ?? ToolExecutionMode::Sequential->value)) ?? ToolExecutionMode::Sequential)->value;
    }

    private function equivalentResults(ToolCallResult $left, ToolCallResult $right): bool
    {
        return $left->toolCallId === $right->toolCallId && $left->orderIndex === $right->orderIndex && $left->isError === $right->isError && $left->result === $right->result && $left->error === $right->error;
    }

    /** @param array<string, mixed> $row */
    private function decodeReference(array $row, string $kind): ToolPayloadReferenceDTO
    {
        $reference = $this->serializer->deserialize($row['body_reference_json'], ToolPayloadReferenceDTO::class, 'json');
        $errors = $this->validator->validate($reference);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($reference, $errors);
        }
        if ($reference->kind !== $kind || $reference->runId !== $row['run_id'] || $reference->turnNo !== (int) $row['turn_no'] || $reference->stepId !== $row['step_id'] || $reference->toolCallId !== $row['tool_call_id'] || $reference->orderIndex !== (int) $row['order_index']) {
            throw new \RuntimeException('Stored tool batch data reference identity is invalid.');
        }

        return $reference;
    }

    /** @param array<mixed> $data */
    private function encodeJson(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $encoded): array
    {
        $data = json_decode((string) $encoded, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \RuntimeException('Stored tool batch JSON is invalid.');
        }

        return $data;
    }

    private function sanitizeRunId(string $runId): void
    {
        if ('' === $runId || str_contains($runId, '/') || str_contains($runId, '\\') || str_contains($runId, "\0")) {
            throw new \RuntimeException('Invalid tool batch run ID.');
        }
    }
}
