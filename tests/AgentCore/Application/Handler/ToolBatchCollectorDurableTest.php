<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\AgentCore\Tests\Support\TestToolBatchStore;
use PHPUnit\Framework\TestCase;

/**
 * Durable coordination proofs use the in-memory SQL-shaped scheduling store.
 */
final class ToolBatchCollectorDurableTest extends TestCase
{
    public function testRepeatedRegistrationPreservesResultsAndDoesNotReadmitExecution(): void
    {
        $store = $this->createStore();
        $calls = [
            $this->executeToolCall('run-register', 'step-register', 'call-1', 0, 'sequential'),
            $this->executeToolCall('run-register', 'step-register', 'call-2', 1, 'sequential'),
        ];
        $collector = new ToolBatchCollector($store);
        $this->assertCount(1, TestToolBatchRegistration::register($collector, $store, 'run-register', 1, 'step-register', $calls));
        \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($collector, $store, $this->toolResult('run-register', 'step-register', 'call-1', 0));
        $before = $store->load('run-register', 1, 'step-register');
        $restored = new ToolBatchCollector($store);
        $this->assertSame([], TestToolBatchRegistration::register($restored, $store, 'run-register', 1, 'step-register', $calls));
        $this->assertEquals($before, $store->load('run-register', 1, 'step-register'));
        \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($restored, $store, $this->toolResult('run-register', 'step-register', 'call-2', 1));
        $this->assertSame([], TestToolBatchRegistration::register($restored, $store, 'run-register', 1, 'step-register', $calls));
        $this->assertTrue($store->load('run-register', 1, 'step-register')->finalized);
        $this->assertCount(2, $store->load('run-register', 1, 'step-register')->results);
    }

    public function testConflictingRegistrationCannotReplaceExistingBatch(): void
    {
        $store = $this->createStore();
        $collector = new ToolBatchCollector($store);
        TestToolBatchRegistration::register($collector, $store, 'run-conflict', 1, 'step-conflict', [$this->executeToolCall('run-conflict', 'step-conflict', 'call', 0, 'sequential')]);
        $before = $store->load('run-conflict', 1, 'step-conflict');
        try {
            TestToolBatchRegistration::register($collector, $store, 'run-conflict', 1, 'step-conflict', [$this->executeToolCall('run-conflict', 'step-conflict', 'different-call', 0, 'sequential')]);
            $this->fail('Conflicting prepared membership must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('Conflicting prepared', $exception->getMessage());
        }
        $this->assertEquals($before, $store->load('run-conflict', 1, 'step-conflict'));
    }

    public function testRegisterAndCollectWithStore(): void
    {
        $store = $this->createStore();
        $collector = new ToolBatchCollector($store, 4);

        $initial = TestToolBatchRegistration::register($collector, $store, 'run-1', 1, 'step-1', [
            $this->executeToolCall('run-1', 'step-1', 'call-1', 0, 'sequential'),
            $this->executeToolCall('run-1', 'step-1', 'call-2', 1, 'sequential'),
        ]);

        $this->assertCount(1, $initial);
        $this->assertSame('call-1', $initial[0]->toolCallId);

        $firstOutcome = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($collector, $store, $this->toolResult('run-1', 'step-1', 'call-1', 0));
        $this->assertTrue($firstOutcome->accepted);
        $this->assertFalse($firstOutcome->complete);
        $this->assertCount(1, $firstOutcome->effectsToDispatch);
        $this->assertSame('call-2', $firstOutcome->effectsToDispatch[0]->toolCallId);

        $loaded = $store->load('run-1', 1, 'step-1');
        $this->assertNotNull($loaded);
        $this->assertFalse($loaded->finalized);
        $this->assertCount(1, $loaded->results);

        $secondOutcome = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($collector, $store, $this->toolResult('run-1', 'step-1', 'call-2', 1));
        $this->assertTrue($secondOutcome->accepted);
        $this->assertTrue($secondOutcome->complete);

        $finalized = $store->load('run-1', 1, 'step-1');
        $this->assertNotNull($finalized);
        $this->assertTrue($finalized->finalized);
    }

    public function testCrossProcessRecoveryWithStore(): void
    {
        $store = $this->createStore();
        $registrar = new ToolBatchCollector($store, 4);

        $initial = TestToolBatchRegistration::register($registrar, $store, 'run-2', 1, 'step-1', [
            $this->executeToolCall('run-2', 'step-1', 'call-1', 0, 'parallel', maxParallelism: 2),
            $this->executeToolCall('run-2', 'step-1', 'call-2', 1, 'parallel', maxParallelism: 2),
        ]);
        $this->assertCount(2, $initial);
        unset($registrar);

        $recovering = new ToolBatchCollector($store, 4);
        $firstOutcome = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($recovering, $store, $this->toolResult('run-2', 'step-1', 'call-1', 0));
        $this->assertTrue($firstOutcome->accepted);
        $this->assertFalse($firstOutcome->complete);
        $this->assertEmpty($firstOutcome->effectsToDispatch);

        $secondOutcome = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($recovering, $store, $this->toolResult('run-2', 'step-1', 'call-2', 1));
        $this->assertTrue($secondOutcome->accepted);
        $this->assertTrue($secondOutcome->complete);
    }

    public function testCrossProcessRecoveryDispatchesPendingCalls(): void
    {
        $store = $this->createStore();
        $registrar = new ToolBatchCollector($store, 2);

        $initial = TestToolBatchRegistration::register($registrar, $store, 'run-3', 1, 'step-1', [
            $this->executeToolCall('run-3', 'step-1', 'call-1', 0, 'parallel', maxParallelism: 2),
            $this->executeToolCall('run-3', 'step-1', 'call-2', 1, 'parallel', maxParallelism: 2),
            $this->executeToolCall('run-3', 'step-1', 'call-3', 2, 'parallel', maxParallelism: 2),
        ]);
        $this->assertCount(2, $initial);
        unset($registrar);

        $recovering = new ToolBatchCollector($store, 2);
        $firstOutcome = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($recovering, $store, $this->toolResult('run-3', 'step-1', 'call-1', 0));
        $this->assertTrue($firstOutcome->accepted);
        $this->assertFalse($firstOutcome->complete);
        $this->assertCount(1, $firstOutcome->effectsToDispatch);
        $this->assertSame('call-3', $firstOutcome->effectsToDispatch[0]->toolCallId);
    }

    public function testRejectedWhenStoreIsEmpty(): void
    {
        $store = $this->createStore();
        $collector = new ToolBatchCollector($store);
        $outcome = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($collector, $store, $this->toolResult('run-nonexistent', 'step-1', 'call-1', 0));
        $this->assertFalse($outcome->accepted);
        $this->assertFalse($outcome->duplicate);
    }

    public function testDuplicateResultWithStore(): void
    {
        $store = $this->createStore();
        $collector = new ToolBatchCollector($store, 4);
        TestToolBatchRegistration::register($collector, $store, 'run-4', 1, 'step-1', [
            $this->executeToolCall('run-4', 'step-1', 'call-1', 0, 'sequential'),
        ]);

        $firstOutcome = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($collector, $store, $this->toolResult('run-4', 'step-1', 'call-1', 0));
        $this->assertTrue($firstOutcome->accepted);

        $dupOutcome = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($collector, $store, $this->toolResult('run-4', 'step-1', 'call-1', 0));
        $this->assertTrue($dupOutcome->accepted);
        $this->assertFalse($dupOutcome->duplicate);
        $this->assertTrue($dupOutcome->complete);
    }

    public function testCrossProcessParallelDispatchRecovery(): void
    {
        $store = $this->createStore();
        $registrar = new ToolBatchCollector($store, 4);
        $initial = TestToolBatchRegistration::register($registrar, $store, 'run-5', 1, 'step-1', [
            $this->executeToolCall('run-5', 'step-1', 'call-1', 0, 'sequential'),
            $this->executeToolCall('run-5', 'step-1', 'call-2', 1, 'parallel', maxParallelism: 4),
            $this->executeToolCall('run-5', 'step-1', 'call-3', 2, 'parallel', maxParallelism: 4),
        ]);
        $this->assertCount(1, $initial);
        unset($registrar);

        $recovering = new ToolBatchCollector($store, 4);
        $firstOutcome = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($recovering, $store, $this->toolResult('run-5', 'step-1', 'call-1', 0));
        $this->assertTrue($firstOutcome->accepted);
        $this->assertFalse($firstOutcome->complete);
        $this->assertCount(2, $firstOutcome->effectsToDispatch);
    }

    public function testFailedDurableSaveDoesNotDirtyInMemoryCache(): void
    {
        $store = new class($this->createStore()) implements ToolBatchStoreInterface {
            public function __construct(private readonly ToolBatchStoreInterface $inner)
            {
            }

            public bool $failNextMutate = false;

            public function load(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO
            {
                return $this->inner->load($runId, $turnNo, $stepId);
            }

            public function delete(string $runId, int $turnNo, string $stepId): void
            {
                $this->inner->delete($runId, $turnNo, $stepId);
            }

            public function hasUnresolvedExecution(string $runId, ?string $toolCallId = null): bool
            {
                return false;
            }

            public function deleteAllForRun(string $runId): void
            {
                $this->inner->deleteAllForRun($runId);
            }

            public function prepareChanges(array $actions, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): \Closure
            {
                $apply = $this->inner->prepareChanges($actions, $transition);

                return function () use ($apply): void {
                    if ($this->failNextMutate) {
                        $this->failNextMutate = false;
                        throw new \RuntimeException('Simulated durable write failure.');
                    }
                    $apply();
                };
            }
        };

        $collector = new ToolBatchCollector($store, 4);
        TestToolBatchRegistration::register($collector, $store, 'run-6', 1, 'step-1', [
            $this->executeToolCall('run-6', 'step-1', 'call-1', 0, 'sequential'),
        ]);

        $store->failNextMutate = true;

        try {
            \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($collector, $store, $this->toolResult('run-6', 'step-1', 'call-1', 0));
            $this->fail('Expected simulated durable write failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated durable write failure.', $e->getMessage());
        }

        $retryOutcome = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($collector, $store, $this->toolResult('run-6', 'step-1', 'call-1', 0));
        $this->assertTrue($retryOutcome->accepted);
        $this->assertFalse($retryOutcome->duplicate);
        $this->assertTrue($retryOutcome->complete);
    }

    public function testDurableHumanInputAdmitResumeRedrivePersistsThroughMutate(): void
    {
        $store = $this->createStore();
        $collector = new ToolBatchCollector($store, 1);
        TestToolBatchRegistration::register($collector, $store, 'run-hi', 1, 'step-hi', [
            $this->executeToolCall('run-hi', 'step-hi', 'call-1', 0, 'sequential'),
        ]);

        // Admit through the shared durable mutate path.
        $this->assertSame([], \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::suspend($collector, $store, 'run-hi', 1, 'step-hi', 'call-1', 'q-1'));
        $stored = $store->load('run-hi', 1, 'step-hi');
        $this->assertNotNull($stored);
        $this->assertSame('q-1', $stored->awaitingHumanInput['call-1'] ?? null);

        // Resume through the shared durable mutate path.
        $answer = new ToolCallHumanInputAnswerDTO(
            questionId: 'q-1',
            answer: '✅ Allow',
            continuationRef: ['run_id' => 'run-hi', 'turn_no' => 1, 'step_id' => 'step-hi', 'tool_call_id' => 'call-1'],
            requestPayload: ['question_id' => 'q-1', 'prompt' => 'Allow?'],
        );
        $resumed = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::resume($collector, $store, 'run-hi', 1, 'step-hi', 'call-1', 'q-1', $answer);
        $this->assertCount(1, $resumed);
        $this->assertSame('call-1', $resumed[0]->toolCallId);

        $stored = $store->load('run-hi', 1, 'step-hi');
        $this->assertNotNull($stored);
        $this->assertArrayNotHasKey('call-1', $stored->awaitingHumanInput);
        $this->assertSame('✅ Allow', $stored->calls['call-1']?->humanInputAnswer?->answer);

        // Redrive through the shared durable mutate path.
        $redriven = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::redrive($collector, $store, 'run-hi', 1, 'step-hi', 'q-1', '✅ Allow');
        $this->assertCount(1, $redriven);
        $this->assertSame('call-1', $redriven[0]->toolCallId);
    }

    public function testDurableHumanInputMissingBatchPreservesExactErrors(): void
    {
        $store = $this->createStore();
        $collector = new ToolBatchCollector($store, 1);

        $cases = [
            [
                static fn (): array => \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::suspend($collector, $store, 'run-missing', 1, 'step-missing', 'call-1', 'q-1'),
                'Cannot admit tool-execution suspension for unknown batch run=run-missing turn=1 step=step-missing.',
            ],
            [
                static fn (): array => \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::resume($collector, $store, 'run-missing', 1, 'step-missing', 'call-1', 'q-1', new ToolCallHumanInputAnswerDTO(
                    questionId: 'q-1',
                    answer: '✅ Allow',
                    continuationRef: [],
                    requestPayload: [],
                )),
                'Cannot resume tool-execution human input for unknown batch run=run-missing turn=1 step=step-missing.',
            ],
            [
                static fn (): array => \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::redrive($collector, $store, 'run-missing', 1, 'step-missing', 'q-1', '✅ Allow'),
                'Cannot redrive tool-execution human input for unknown batch run=run-missing turn=1 step=step-missing.',
            ],
        ];

        foreach ($cases as [$operation, $expectedMessage]) {
            try {
                $operation();
                $this->fail('Expected LogicException for unknown batch.');
            } catch (\LogicException $e) {
                $this->assertSame($expectedMessage, $e->getMessage());
            }
        }
    }

    private function createStore(): ToolBatchStoreInterface
    {
        return new TestToolBatchStore();
    }

    private function executeToolCall(
        string $runId,
        string $stepId,
        string $toolCallId,
        int $orderIndex,
        string $mode,
        int $maxParallelism = 4,
    ): ExecuteToolCall {
        return new ExecuteToolCall(
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $toolCallId)),
            toolCallId: $toolCallId,
            toolName: 'web_search',
            args: [],
            orderIndex: $orderIndex,
            mode: $mode,
            maxParallelism: $maxParallelism,
        );
    }

    private function toolResult(string $runId, string $stepId, string $toolCallId, int $orderIndex): ToolCallResult
    {
        return new ToolCallResult(
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $toolCallId)),
            toolCallId: $toolCallId,
            orderIndex: $orderIndex,
            result: ['ok' => true],
            isError: false,
            error: null,
        );
    }
}
