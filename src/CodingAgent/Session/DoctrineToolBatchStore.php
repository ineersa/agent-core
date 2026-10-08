<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/** One SQL row owns the active batch's calls, results, and scheduling state. */
final readonly class DoctrineToolBatchStore implements ToolBatchStoreInterface
{
    private PhpSerializer $serializer;

    public function __construct(private Connection $connection)
    {
        $this->serializer = new PhpSerializer();
    }

    public function load(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO
    {
        $record = $this->record($runId, $turnNo, $stepId);
        if (null === $record) {
            return null;
        }
        $calls = [];
        foreach ($this->decodeJson($record['calls_json']) as $id => $encoded) {
            $call = $this->decodeMessage($encoded);
            if (!$call instanceof ExecuteToolCall || $call->toolCallId !== (string) $id || $call->runId() !== $runId || $call->turnNo() !== $turnNo || $call->stepId() !== $stepId) {
                throw new \RuntimeException('Stored tool batch call identity is invalid.');
            }
            $calls[$id] = $call;
        }
        $results = [];
        foreach ($this->decodeJson($record['results_json']) as $id => $encoded) {
            $result = $this->decodeMessage($encoded);
            if (!$result instanceof ToolCallResult || $result->toolCallId !== (string) $id || $result->runId() !== $runId || $result->turnNo() !== $turnNo || $result->stepId() !== $stepId) {
                throw new \RuntimeException('Stored tool batch result identity is invalid.');
            }
            $results[$id] = $result;
        }
        /** @var array<string, int> $order */
        $order = $this->decodeJson($record['expected_order_json']);
        /** @var list<string> $pending */
        $pending = $this->decodeJson($record['pending_queue_json']);
        /** @var list<string> $inFlightIds */
        $inFlightIds = $this->decodeJson($record['in_flight_json']);
        /** @var array<string, string> $awaiting */
        $awaiting = $this->decodeJson($record['awaiting_human_input_json']);

        return new ToolBatchStateDTO($order, $calls, $pending, array_fill_keys($inFlightIds, true), $results, (bool) $record['finalized'], max(1, (int) $record['max_parallelism']), $awaiting);
    }

    public function prepareChanges(array $actions, VerifiedTransitionDTO $transition): \Closure
    {
        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];
        /** @var array<string, ToolBatchStateDTO> $batches */
        $batches = [];
        foreach ($actions as $action) {
            if (($transition->work['run_id'] ?? null) !== $action->runId) {
                throw new \RuntimeException('Prepared batch requires matching verified transition.');
            }
            $expectedActions = [...($transition->work['actions'] ?? []), ...($transition->work['after_turn_actions'] ?? [])];
            $matched = false;
            foreach ($expectedActions as $expected) {
                if (serialize($expected) === serialize($action)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new \RuntimeException('Prepared batch differs from captured transition work.');
            }
            $key = $action->runId.'|'.$action->turnNo.'|'.$action->stepId;
            $record = $this->record($action->runId, $action->turnNo, $action->stepId);
            if (null !== $record && $record['applied_transition'] === $transition->identity) {
                continue;
            }
            $batch = $batches[$key] ?? $this->load($action->runId, $action->turnNo, $action->stepId);
            if ($action instanceof RegisterToolBatchDTO) {
                $calls = [];
                foreach ($action->effects as $call) {
                    $calls[$call->toolCallId] = $call;
                }
                if (null !== $batch) {
                    if ($batch->expectedOrder !== $action->expectedOrder || serialize($batch->calls) !== serialize($calls)) {
                        throw new \LogicException('Conflicting prepared tool batch registration.');
                    }
                // Redelivery never resets collected siblings or current queue state.
                } else {
                    $batch = new ToolBatchStateDTO($action->expectedOrder, $calls, $action->pendingQueue, $action->inFlight, [], false, max(1, $action->maxParallelism));
                }
            } else {
                if (null === $batch) {
                    throw new \RuntimeException('Prepared batch evidence is missing.');
                }
                if (null !== $action->result) {
                    $batch->results[$action->result->toolCallId] = $action->result;
                }
                if (null !== $action->revisedCallId) {
                    $call = $batch->calls[$action->revisedCallId] ?? throw new \RuntimeException('Prepared human answer has no stored call.');
                    $batch->calls[$action->revisedCallId] = null === $action->answer
                        ? $call->withHumanInputAnswer(null) : $call->withAuthorizedHumanAnswer($action->answer);
                }
                $batch->pendingQueue = $action->pendingQueue;
                $batch->inFlight = $action->inFlight;
                $batch->awaitingHumanInput = $action->awaitingHumanInput;
                $batch->finalized = $action->finalized;
            }
            $batches[$key] = $batch;
            // Serialization belongs outside the short mailbox/batch transaction.
            $rows[$key] = $this->encodeRow($action->runId, $action->turnNo, $action->stepId, $batch, $transition->identity);
        }

        return function () use ($rows): void {
            foreach ($rows as $row) {
                $key = ['run_id' => $row['run_id'], 'turn_no' => $row['turn_no'], 'step_id' => $row['step_id']];
                if (false === $this->connection->fetchOne('SELECT 1 FROM tool_batch_schedule WHERE run_id = ? AND turn_no = ? AND step_id = ?', array_values($key))) {
                    $this->connection->insert('tool_batch_schedule', $row);
                } else {
                    $this->connection->update('tool_batch_schedule', $row, $key);
                }
            }
        };
    }

    public function delete(string $runId, int $turnNo, string $stepId): void
    {
        $this->sanitizeRunId($runId);
        $this->connection->executeStatement(
            "DELETE FROM tool_batch_schedule WHERE run_id = ? AND turn_no = ? AND step_id = ? AND finalized = 1 AND awaiting_human_input_json = '[]' AND pending_queue_json = '[]' AND in_flight_json = '[]' AND NOT EXISTS (SELECT 1 FROM deferred_tool_completion d WHERE d.run_id = tool_batch_schedule.run_id AND d.turn_no = tool_batch_schedule.turn_no AND d.step_id = tool_batch_schedule.step_id AND d.status = 'pending')",
            [$runId, $turnNo, $stepId],
        );
    }

    public function deleteAllForRun(string $runId): void
    {
        $this->sanitizeRunId($runId);
        $this->connection->executeStatement(
            "DELETE FROM tool_batch_schedule WHERE run_id = ? AND finalized = 1 AND awaiting_human_input_json = '[]' AND pending_queue_json = '[]' AND in_flight_json = '[]' AND NOT EXISTS (SELECT 1 FROM deferred_tool_completion d WHERE d.run_id = tool_batch_schedule.run_id AND d.turn_no = tool_batch_schedule.turn_no AND d.step_id = tool_batch_schedule.step_id AND d.status = 'pending')",
            [$runId],
        );
    }

    public function hasUnresolvedExecution(string $runId, ?string $toolCallId = null): bool
    {
        $this->sanitizeRunId($runId);
        $params = [$runId];
        $sql = "SELECT 1 FROM deferred_tool_completion WHERE run_id = ? AND status = 'pending'";
        if (null !== $toolCallId) {
            $sql .= ' AND tool_call_id = ?';
            $params[] = $toolCallId;
        }
        if (false !== $this->connection->fetchOne($sql.' LIMIT 1', $params)) {
            return true;
        }
        foreach ($this->connection->fetchAllAssociative('SELECT calls_json, finalized, awaiting_human_input_json, pending_queue_json, in_flight_json FROM tool_batch_schedule WHERE run_id = ?', [$runId]) as $record) {
            if (null !== $toolCallId && !isset($this->decodeJson($record['calls_json'])[$toolCallId])) {
                continue;
            }
            if (!(bool) $record['finalized'] || [] !== $this->decodeJson($record['awaiting_human_input_json']) || [] !== $this->decodeJson($record['pending_queue_json']) || [] !== $this->decodeJson($record['in_flight_json'])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function record(string $runId, int $turnNo, string $stepId): ?array
    {
        $this->sanitizeRunId($runId);
        $row = $this->connection->fetchAssociative('SELECT * FROM tool_batch_schedule WHERE run_id = ? AND turn_no = ? AND step_id = ?', [$runId, $turnNo, $stepId]);

        return false === $row ? null : $row;
    }

    /** @return array<string, mixed> */
    private function encodeRow(string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $batch, string $identity): array
    {
        $calls = [];
        foreach ($batch->calls as $id => $call) {
            $calls[$id] = $this->serializer->encode(new Envelope($call));
        }
        $results = [];
        foreach ($batch->results as $id => $result) {
            $results[$id] = $this->serializer->encode(new Envelope($result));
        }

        return [
            'run_id' => $runId,
            'turn_no' => $turnNo,
            'step_id' => $stepId,
            'max_parallelism' => $batch->maxParallelism,
            'finalized' => $batch->finalized ? 1 : 0,
            'expected_order_json' => $this->encodeJson($batch->expectedOrder),
            'pending_queue_json' => $this->encodeJson($batch->pendingQueue),
            'in_flight_json' => $this->encodeJson(array_keys($batch->inFlight)),
            'awaiting_human_input_json' => $this->encodeJson($batch->awaitingHumanInput),
            'calls_json' => $this->encodeJson($calls),
            'results_json' => $this->encodeJson($results),
            'applied_transition' => $identity,
        ];
    }

    private function decodeMessage(mixed $encoded): object
    {
        if (!\is_array($encoded) || !\is_string($encoded['body'] ?? null)) {
            throw new \RuntimeException('Stored tool batch message is invalid.');
        }

        return $this->serializer->decode($encoded)->getMessage();
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
