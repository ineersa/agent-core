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
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Repository\RunRelationshipReader;
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

    public function testReplaceMapsOnlyBoundedOperationalGraphAndReplacesCurrentChildren(): void
    {
        $this->repository->replace($this->state(
            RunStatus::WaitingHuman,
            [
                new CurrentToolCallDTO('batch-1', 'tool-1', 0, RunOperationalToolCallStatusEnum::Running, 2),
                new CurrentToolCallDTO('batch-1', 'tool-2', 1, RunOperationalToolCallStatusEnum::WaitingHuman, 2),
            ],
            [
                new PendingHumanInputRequestDTO('question-1', HumanInputContinuationKindEnum::ToolCall, ['question_id' => 'question-1'], ['tool_call_id' => 'tool-2']),
            ],
        ));

        $this->assertSame(['run-1', null, 'run-1', 'waiting_human', 3, 'step-3'], $this->connection->fetchNumeric('SELECT run_id, parent_run_id, owner_session_id, status, turn_no, active_step_id FROM run_operational_state WHERE run_id = ?', ['run-1']));
        $this->assertSame([['tool-1', 'running', 2], ['tool-2', 'waiting_human', 2]], $this->connection->fetchAllNumeric('SELECT tool_call_id, status, attempt FROM run_operational_tool_call ORDER BY order_index'));
        $this->assertSame([['question-1', 'tool_call', 'tool-2', 'waiting']], $this->connection->fetchAllNumeric('SELECT question_id, continuation_kind, tool_call_id, status FROM run_operational_human_input'));

        // Reuse managed children with the same composite identifiers instead of
        // replacing them with duplicate objects in Doctrine's identity map.
        $this->repository->replace($this->state(
            RunStatus::Running,
            [
                new CurrentToolCallDTO('batch-1', 'tool-1', 1, RunOperationalToolCallStatusEnum::Completed, 3),
                new CurrentToolCallDTO('batch-2', 'tool-3', 0, RunOperationalToolCallStatusEnum::Pending, 1),
            ],
            [
                new PendingHumanInputRequestDTO('question-1', HumanInputContinuationKindEnum::ToolCall, ['question_id' => 'question-1'], ['tool_call_id' => 'tool-1']),
            ],
        ));
        $this->assertSame(
            [['batch-1', 'tool-1', 'completed', 3], ['batch-2', 'tool-3', 'pending', 1]],
            $this->connection->fetchAllNumeric('SELECT batch_id, tool_call_id, status, attempt FROM run_operational_tool_call ORDER BY batch_id, tool_call_id'),
        );
        $this->assertSame([['question-1', 'tool-1']], $this->connection->fetchAllNumeric('SELECT question_id, tool_call_id FROM run_operational_human_input'));

        $this->repository->replace($this->state(RunStatus::Completed));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM run_operational_tool_call'));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM run_operational_human_input'));
    }

    public function testStatusReconstructsCurrentOperationAndOwnerCleanupRemovesGraph(): void
    {
        $this->repository->replace($this->state(RunStatus::Cancelling));
        $status = $this->repository->findOperationalStatus('run-1');
        $this->assertSame(RunStatus::Cancelling, $status?->status);
        $this->assertTrue($status?->currentOperation?->matches(3, 'step-3', 2, 'operation-3') ?? false);

        $this->assertSame(1, $this->repository->deleteForOwnerSession('run-1'));
        $this->assertNull($this->repository->findOperationalStatus('run-1'));
        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM run_operational_tool_call'));
    }

    public function testParentRunIdFromRunStateMapsOwnershipWithoutMetadataReader(): void
    {
        $this->repository->replace(new RunState('child', RunStatus::Running, parentRunId: 'parent'));
        $this->assertSame(['parent', 'parent'], $this->connection->fetchNumeric('SELECT parent_run_id, owner_session_id FROM run_operational_state WHERE run_id = ?', ['child']));

        $this->repository->replace(new RunState('child', RunStatus::Completed, parentRunId: 'parent'));
        $this->assertSame(['parent', 'parent'], $this->connection->fetchNumeric('SELECT parent_run_id, owner_session_id FROM run_operational_state WHERE run_id = ?', ['child']));
    }

    public function testLiveChildStartStateProjectsAsChildNotTopLevel(): void
    {
        // Mirrors StartRunHandler live parentRunId extraction so a newly launched
        // child is never written as a top-level operational row before replay.
        $liveChild = new RunState(
            runId: 'child-live-proj',
            status: RunStatus::Running,
            model: 'deepseek/deepseek-v4-flash',
            parentRunId: 'parent-live-proj',
        );

        $this->repository->replace($liveChild);

        $this->assertSame(
            ['parent-live-proj', 'parent-live-proj'],
            $this->connection->fetchNumeric(
                'SELECT parent_run_id, owner_session_id FROM run_operational_state WHERE run_id = ?',
                ['child-live-proj'],
            ),
        );

        $reader = new RunRelationshipReader($this->repository);
        $this->assertTrue($reader->isAgentChild('child-live-proj'));
        $this->assertSame('parent-live-proj', $reader->readParentRunId('child-live-proj'));
    }

    public function testValidationFailureDoesNotDirtyManagedProjection(): void
    {
        $this->repository->replace($this->state(RunStatus::Running));
        $invalid = new RunState('run-1', RunStatus::Running, activeStepId: str_repeat('x', 256));

        $this->expectException(ValidationFailedException::class);
        try {
            $this->repository->replace($invalid);
        } finally {
            $this->repository->replace($this->state(RunStatus::Completed));
            $this->assertSame(RunStatus::Completed->value, $this->connection->fetchOne('SELECT status FROM run_operational_state WHERE run_id = ?', ['run-1']));
        }
    }

    /** @param list<CurrentToolCallDTO> $toolCalls @param list<PendingHumanInputRequestDTO> $humanInputs */
    private function state(RunStatus $status, array $toolCalls = [], array $humanInputs = []): RunState
    {
        return new RunState(
            'run-1',
            $status,
            version: 7,
            turnNo: 3,
            lastSeq: 11,
            activeStepId: 'step-3',
            currentOperation: new CurrentOperationDTO(3, 'step-3', 2, 'operation-3'),
            lastAppliedAdvanceKey: 'advance-3',
            lastAppliedCompactionKey: 'compact-3',
            retryableFailure: true,
            retryAttempts: 2,
            currentToolCalls: $toolCalls,
            pendingHumanInputRequests: $humanInputs,
        );
    }
}
