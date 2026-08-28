<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Repository;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\CurrentToolCallDTO;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Entity\RunOperationalHumanInput;
use Ineersa\CodingAgent\Entity\RunOperationalState;
use Ineersa\CodingAgent\Entity\RunOperationalToolCall;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\Projection\RunOperationalProjectionWriter;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

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

    public function testParentAndChildProjectionCleanupIsScopedAndCascadesDependencies(): void
    {
        $this->repository->replace($this->projection('parent', 'session-a', RunStatus::Running));

        $child = $this->projection('child', 'session-a', RunStatus::WaitingHuman);
        $child->addToolCall(new RunOperationalToolCall($child, 'batch-1', 'tool-1', 0, RunOperationalToolCallStatusEnum::Pending, 1));
        $child->addHumanInput(new RunOperationalHumanInput($child, 'question-1', 0, HumanInputContinuationKindEnum::ToolCall, 'tool-1'));
        $this->repository->replace($child);
        $this->repository->replace($this->projection('other', 'session-b', RunStatus::Queued));

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
        $this->repository->replace(new RunOperationalState(
            'run-1', 'session-1', RunStatus::Cancelling, 4, 'step-4', new CurrentOperationDTO(4, 'step-4', 2, 'operation-4'),
            'advance-4', 'compact-4', true, 3, 19, 8,
        ));

        $status = $this->repository->findOperationalStatus('run-1');
        $this->assertSame(RunStatus::Cancelling, $status?->status);
        $this->assertTrue($status?->currentOperation?->matches(4, 'step-4', 2, 'operation-4') ?? false);
        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM run_operational_state WHERE run_id = ?', ['run-1']));
        $this->assertSame(['advance-4', 'compact-4', 3, 19, 8], $this->connection->fetchNumeric('SELECT last_applied_advance_key, last_applied_compaction_key, retry_attempts, last_event_sequence, transition_version FROM run_operational_state WHERE run_id = ?', ['run-1']));
    }

    public function testReplacingCurrentToolAndHumanInputEntitiesPreservesOnlyCurrentRows(): void
    {
        $first = $this->projection('run-1', 'session-1', RunStatus::Running);
        $first->addToolCall(new RunOperationalToolCall($first, 'batch-1', 'tool-2', 1, RunOperationalToolCallStatusEnum::Running, 2));
        $first->addToolCall(new RunOperationalToolCall($first, 'batch-1', 'tool-1', 0, RunOperationalToolCallStatusEnum::Pending, 1));
        $first->addHumanInput(new RunOperationalHumanInput($first, 'question-2', 1, HumanInputContinuationKindEnum::ModelTurn, null));
        $first->addHumanInput(new RunOperationalHumanInput($first, 'question-1', 0, HumanInputContinuationKindEnum::ToolCall, 'tool-1'));
        $this->repository->replace($first);

        $this->assertSame([['tool-1', 0, 'pending', 1], ['tool-2', 1, 'running', 2]], $this->connection->fetchAllNumeric('SELECT tool_call_id, order_index, status, attempt FROM run_operational_tool_call ORDER BY order_index'));
        $this->assertSame([['question-1', 0, 'tool_call', 'tool-1', 'waiting'], ['question-2', 1, 'model_turn', null, 'waiting']], $this->connection->fetchAllNumeric('SELECT question_id, order_index, continuation_kind, tool_call_id, status FROM run_operational_human_input ORDER BY order_index'));

        $second = $this->projection('run-1', 'session-1', RunStatus::Running);
        $second->addToolCall(new RunOperationalToolCall($second, 'batch-2', 'tool-3', 0, RunOperationalToolCallStatusEnum::Pending, 1));
        $this->repository->replace($second);

        $this->assertSame([['batch-2', 'tool-3']], $this->connection->fetchAllNumeric('SELECT batch_id, tool_call_id FROM run_operational_tool_call'));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM run_operational_human_input'));
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

    public function testSymfonyValidationRejectsAnUnboundedProjectionBeforeFlush(): void
    {
        $this->expectException(ValidationFailedException::class);

        $this->repository->replace($this->projection(str_repeat('x', 256), 'session-1', RunStatus::Running));
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

    private function projection(string $runId, string $ownerSessionId, RunStatus $status): RunOperationalState
    {
        return new RunOperationalState($runId, $ownerSessionId, $status, 0, null, null, null, null, false, 0, 0, 0);
    }
}
