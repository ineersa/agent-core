<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Entity;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\CurrentToolCallDTO;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Entity\RunOperationalHumanInputDTO;
use Ineersa\CodingAgent\Entity\RunOperationalProjectionDTO;
use Ineersa\CodingAgent\Entity\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Entity\RunOperationalProjectionWriter;
use Ineersa\CodingAgent\Entity\RunOperationalToolCallDTO;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class RunOperationalProjectionRepositoryTest extends IsolatedKernelTestCase
{
    private Connection $connection;
    private RunOperationalProjectionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get('test.run_operational_projection_repository');
    }

    public function testAggregateProjectionAndStatusMetricsContainOnlyBoundedCounts(): void
    {
        $metrics = new RunMetrics();
        $writer = new RunOperationalProjectionWriter(new RunOperationalProjectionRepository($this->connection, $metrics));
        $writer->replace('alpha', new RunState('alpha', RunStatus::Running));
        $writer->replace('alpha', new RunState('beta', RunStatus::Running));

        $repository = new RunOperationalProjectionRepository($this->connection, $metrics);
        $this->assertSame(RunStatus::Running, $repository->findOperationalStatus('alpha')?->status);
        $this->assertNull($repository->findOperationalStatus('missing'));

        $snapshot = $metrics->snapshot();
        $this->assertSame(['state' => 2, 'tool' => 0, 'human' => 0], $snapshot['projection_replacements']['rows_written']);
        $this->assertSame(['parent' => 1, 'child' => 1], $snapshot['projection_replacements']['owner_kind']);
        $this->assertSame(43, $snapshot['projection_replacements']['logical_scalar_bytes']);
        $this->assertSame(['attempts' => 2, 'misses' => 1, 'errors' => 0], array_intersect_key($snapshot['operational_status_reads'], array_flip(['attempts', 'misses', 'errors'])));
        $this->assertStringNotContainsString('alpha', json_encode($snapshot, \JSON_THROW_ON_ERROR));
    }

    public function testParentAndChildProjectionCleanupIsScopedAndCascadesDependencies(): void
    {
        $this->repository->replace($this->projection('parent', 'session-a', RunStatus::Running));
        $this->repository->replace($this->projection('child', 'session-a', RunStatus::WaitingHuman));
        $this->repository->replace($this->projection('other', 'session-b', RunStatus::Queued));
        $this->repository->replaceToolCalls('child', [new RunOperationalToolCallDTO('batch-1', 'tool-1', 0, 'pending', 1)]);
        $this->repository->replaceHumanInputs('child', [new RunOperationalHumanInputDTO('question-1', 0, 'tool_call', 'tool-1', 'waiting')]);

        $this->assertSame(2, $this->repository->deleteForOwnerSession('session-a'));
        $this->assertNull($this->repository->findOperationalStatus('parent'));
        $this->assertNull($this->repository->findOperationalStatus('child'));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM run_operational_tool_call'));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM run_operational_human_input'));
        $this->assertSame(RunStatus::Queued, $this->repository->findOperationalStatus('other')?->status);
    }

    public function testReplaceRoundTripsBoundedCurrentIdentityAndOrdinarilyUpdates(): void
    {
        $this->repository->replace($this->projection('run-1', 'session-1', RunStatus::Running));
        $this->repository->replace(new RunOperationalProjectionDTO(
            'run-1', 'session-1', RunStatus::Cancelling, 4, 'step-4', new CurrentOperationDTO(4, 'step-4', 2, 'operation-4'),
            'advance-4', 'compact-4', true, 3, 19, 8,
        ));

        $status = $this->repository->findOperationalStatus('run-1');
        $this->assertSame(RunStatus::Cancelling, $status?->status);
        $this->assertTrue($status?->cancellationRequested() ?? false);
        $this->assertTrue($status?->currentOperation?->matches(4, 'step-4', 2, 'operation-4') ?? false);
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM run_operational_state WHERE run_id = ?', ['run-1']));
        $this->assertSame(['advance-4', 'compact-4', 3, 19, 8], $this->connection->fetchNumeric('SELECT last_applied_advance_key, last_applied_compaction_key, retry_attempts, last_event_sequence, transition_version FROM run_operational_state WHERE run_id = ?', ['run-1']));
    }

    public function testReplacingCurrentToolAndHumanInputRowsPreservesOnlyCoordinationColumns(): void
    {
        $this->repository->replace($this->projection('run-1', 'session-1', RunStatus::Running));
        $this->repository->replaceToolCalls('run-1', [
            new RunOperationalToolCallDTO('batch-1', 'tool-2', 1, 'running', 2),
            new RunOperationalToolCallDTO('batch-1', 'tool-1', 0, 'pending', 1),
        ]);
        $this->repository->replaceHumanInputs('run-1', [
            new RunOperationalHumanInputDTO('question-2', 1, 'model_turn', null, 'waiting'),
            new RunOperationalHumanInputDTO('question-1', 0, 'tool_call', 'tool-1', 'waiting'),
        ]);

        $this->assertSame([['tool-1', 0, 'pending', 1], ['tool-2', 1, 'running', 2]], $this->connection->fetchAllNumeric('SELECT tool_call_id, order_index, status, attempt FROM run_operational_tool_call ORDER BY order_index'));
        $this->assertSame([['question-1', 0, 'tool_call', 'tool-1', 'waiting'], ['question-2', 1, 'model_turn', null, 'waiting']], $this->connection->fetchAllNumeric('SELECT question_id, order_index, continuation_kind, tool_call_id, status FROM run_operational_human_input ORDER BY order_index'));

        $this->repository->replaceToolCalls('run-1', [new RunOperationalToolCallDTO('batch-2', 'tool-3', 0, 'pending', 1)]);
        $this->assertSame([['batch-2', 'tool-3']], $this->connection->fetchAllNumeric('SELECT batch_id, tool_call_id FROM run_operational_tool_call'));
    }

    public function testProjectionWriterMapsOnlyCurrentStateAndHumanInputIdentities(): void
    {
        $writer = new RunOperationalProjectionWriter($this->repository);
        $writer->replace('session-1', new RunState(
            'child-1', RunStatus::WaitingHuman, version: 7, turnNo: 3, lastSeq: 11,
            activeStepId: 'step-3', currentOperation: new CurrentOperationDTO(3, 'step-3', 2, 'operation-3'),
            lastAppliedAdvanceKey: 'advance-3', lastAppliedCompactionKey: 'compact-3', retryableFailure: true, retryAttempts: 2,
            errorMessage: 'not persisted', model: 'not persisted',
            currentToolCalls: [
                new CurrentToolCallDTO('batch-3', 'tool-1', 0, RunOperationalToolCallStatusEnum::Completed, 2),
                new CurrentToolCallDTO('batch-3', 'tool-2', 1, RunOperationalToolCallStatusEnum::WaitingHuman, 2),
            ],
            pendingHumanInputRequests: [
                new PendingHumanInputRequestDTO('question-1', HumanInputContinuationKindEnum::ModelTurn, ['question_id' => 'question-1', 'prompt' => 'not persisted']),
                new PendingHumanInputRequestDTO('question-2', HumanInputContinuationKindEnum::ToolCall, ['question_id' => 'question-2'], ['run_id' => 'child-1', 'turn_no' => 3, 'step_id' => 'step-3', 'tool_call_id' => 'tool-2']),
            ],
        ));

        $this->assertSame(['session-1', 'waiting_human', 3, 'step-3', 3, 'step-3', 2, 'operation-3', 'advance-3', 'compact-3', 1, 2, 11, 7], $this->connection->fetchNumeric('SELECT owner_session_id, status, turn_no, active_step_id, operation_turn_no, operation_step_id, operation_attempt, operation_key, last_applied_advance_key, last_applied_compaction_key, retryable_failure, retry_attempts, last_event_sequence, transition_version FROM run_operational_state WHERE run_id = ?', ['child-1']));
        $this->assertSame([['question-1', 0, 'model_turn', null, 'waiting'], ['question-2', 1, 'tool_call', 'tool-2', 'waiting']], $this->connection->fetchAllNumeric('SELECT question_id, order_index, continuation_kind, tool_call_id, status FROM run_operational_human_input WHERE run_id = ? ORDER BY order_index', ['child-1']));
        $this->assertSame([['batch-3', 'tool-1', 0, 'completed', 2], ['batch-3', 'tool-2', 1, 'waiting_human', 2]], $this->connection->fetchAllNumeric('SELECT batch_id, tool_call_id, order_index, status, attempt FROM run_operational_tool_call WHERE run_id = ? ORDER BY order_index', ['child-1']));

        $writer->replace('session-1', new RunState('child-1', RunStatus::Running));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM run_operational_tool_call WHERE run_id = ?', ['child-1']));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM run_operational_human_input WHERE run_id = ?', ['child-1']));
    }

    public function testSchemaContainsOnlyApprovedPayloadFreeColumns(): void
    {
        $schema = $this->connection->createSchemaManager();
        $expected = [
            'run_operational_state' => ['run_id', 'owner_session_id', 'status', 'turn_no', 'active_step_id', 'operation_turn_no', 'operation_step_id', 'operation_attempt', 'operation_key', 'last_applied_advance_key', 'last_applied_compaction_key', 'retryable_failure', 'retry_attempts', 'last_event_sequence', 'transition_version', 'created_at', 'updated_at'],
            'run_operational_tool_call' => ['run_id', 'batch_id', 'tool_call_id', 'order_index', 'status', 'attempt', 'created_at', 'updated_at'],
            'run_operational_human_input' => ['run_id', 'question_id', 'order_index', 'continuation_kind', 'tool_call_id', 'status', 'created_at', 'updated_at'],
        ];
        foreach ($expected as $table => $columns) {
            $actual = $schema->listTableColumns($table);
            $this->assertSame($columns, array_keys($actual));
            foreach ($actual as $column) {
                $this->assertNotContains($column->getType()::class, [\Doctrine\DBAL\Types\JsonType::class, \Doctrine\DBAL\Types\BlobType::class, \Doctrine\DBAL\Types\TextType::class]);
            }
        }
    }

    private function projection(string $runId, string $ownerSessionId, RunStatus $status): RunOperationalProjectionDTO
    {
        return new RunOperationalProjectionDTO($runId, $ownerSessionId, $status, 0, null, null, null, null, false, 0, 0, 0);
    }
}
