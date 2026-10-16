<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\TransitionFinalizer;
use Ineersa\AgentCore\Application\Pipeline\TransitionPlanFactory;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Extension\EssentialAfterTurnHookInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationAcceptedCompletionIdentity;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Messenger\MessageBusInterface;

#[Group('db')]
final class ToolInvocationLifecyclePersistenceTest extends IsolatedKernelTestCase
{
    public function testLifecycleProjectionIsIdempotentAndRecoversAfterInterruptedFinalization(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('lifecycle persistence');
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);
        $correlation = $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440400',
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'lifecycle-key',
            toolCallId: 'call-lifecycle',
            toolName: 'bash',
            arguments: ['command' => 'sleep 30'],
            orderIndex: 0,
            executionOwnerKind: 'bash',
            executionOwnerReference: new ToolInvocationOwnerReference('bash', 'record-lifecycle', 1001, '/tmp/lifecycle.log'),
            originalExecutionMode: 'sequential',
        ));

        $this->assertSame(ToolInvocationCallerDispositionEnum::ForegroundPending, $correlation->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Active, $correlation->executionObservation);
        $this->assertNotNull($correlation->envelopeFingerprint);
        $this->assertSame('record-lifecycle', $correlation->executionOwnerReference?->recordId);

        $action = new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [$action]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);

        $coordinator = new LocalMetadataCoordinator(
            $container->get(ApplicationDbTransactionInterface::class),
            $container->get(ToolBatchStoreInterface::class),
            $container->get(CommandStoreInterface::class),
            $repo,
        );
        $coordinator->apply(new TransitionPlan($run, $pending, [$action], [], []));
        $store->finalizeVerifiedTransition($run, $pending->identity);

        $updated = $repo->findByRunAndToolCall($run, 'call-lifecycle');
        $this->assertNotNull($updated);
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $updated->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Active, $updated->executionObservation);
        $this->assertSame('pending', $repo->status($correlation->deferredId));

        // Idempotent re-application of the same captured projection must not change state.
        $repo->applyLifecycleProjection($action);
        $again = $repo->findByRunAndToolCall($run, 'call-lifecycle');
        $this->assertNotNull($again);
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $again->callerDisposition);

        $mismatch = new DeferredToolCompletionCorrelation(
            deferredId: $correlation->deferredId,
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'lifecycle-key',
            toolCallId: 'call-lifecycle',
            toolName: 'bash',
            arguments: ['command' => 'sleep 999'],
            orderIndex: 0,
        );
        try {
            $repo->registerPending($mismatch);
            $this->fail('Envelope mismatch must refuse registration reuse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('envelope mismatch', $exception->getMessage());
        }

        $cancelAction = new RecordToolInvocationLifecycleDTO(
            $updated,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::CancelRequested,
        );
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [$cancelAction]]);
        $this->assertNotNull($store->verifiedPendingTransition($run));

        $bus = $container->get('agent.command.bus');
        $this->recovery($bus)->recover($run);
        $this->assertNull($store->verifiedPendingTransition($run));
        $cancelled = $repo->findByRunAndToolCall($run, 'call-lifecycle');
        $this->assertNotNull($cancelled);
        $this->assertSame(ToolInvocationExecutionObservationEnum::CancelRequested, $cancelled->executionObservation);
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $cancelled->callerDisposition);
        $this->assertSame('pending', $repo->status($correlation->deferredId));
    }

    public function testLifecycleProjectionRollsBackWithSiblingFailureThenRecovers(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('lifecycle rollback');
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);
        $correlation = $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440401',
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'lifecycle-rollback-key',
            toolCallId: 'call-lifecycle-rollback',
            toolName: 'bash',
            arguments: ['command' => 'sleep 30'],
            orderIndex: 0,
        ));

        $lifecycle = new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $mailbox = new EnqueueCommandDTO(new PendingCommand($run, 'follow_up', 'lifecycle-mailbox', ['text' => 'keep']));
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [$lifecycle, $mailbox]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $plan = new TransitionPlan($run, $pending, [$lifecycle, $mailbox], [], []);

        $failingCommands = $this->createStub(CommandStoreInterface::class);
        $failingCommands->method('prepareEnqueue')->willReturn(static function (): bool {
            throw new \RuntimeException('Injected mailbox failure after lifecycle.');
        });
        $failingCoordinator = new LocalMetadataCoordinator(
            $container->get(ApplicationDbTransactionInterface::class),
            $container->get(ToolBatchStoreInterface::class),
            $failingCommands,
            $repo,
        );

        try {
            $failingCoordinator->apply($plan);
            $this->fail('Sibling mailbox failure must roll back lifecycle projection.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected mailbox failure after lifecycle.', $exception->getMessage());
        }

        $rolledBack = $repo->findByRunAndToolCall($run, 'call-lifecycle-rollback');
        $this->assertNotNull($rolledBack);
        $this->assertSame(ToolInvocationCallerDispositionEnum::ForegroundPending, $rolledBack->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Active, $rolledBack->executionObservation);
        $this->assertFalse($container->get(CommandStoreInterface::class)->has($run, 'lifecycle-mailbox'));
        $this->assertNotNull($store->verifiedPendingTransition($run));

        $bus = $container->get('agent.command.bus');
        $this->recovery($bus)->recover($run);
        $this->assertNull($store->verifiedPendingTransition($run));
        $recovered = $repo->findByRunAndToolCall($run, 'call-lifecycle-rollback');
        $this->assertNotNull($recovered);
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $recovered->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Active, $recovered->executionObservation);
        $this->assertTrue($container->get(CommandStoreInterface::class)->has($run, 'lifecycle-mailbox'));
        $this->assertSame('pending', $repo->status($correlation->deferredId));
    }

    public function testLifecycleProjectionWorksForPersistedNullFingerprintRow(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('lifecycle null fingerprint');
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);
        /** @var Connection $connection */
        $connection = $container->get('doctrine.dbal.default_connection');

        $deferredId = '550e8400-e29b-41d4-a716-446655440402';
        $toolCallId = 'call-lifecycle-null-fingerprint';
        $arguments = ['command' => 'sleep 5'];
        $now = '2026-10-16 12:00:00';
        $connection->insert('deferred_tool_completion', [
            'deferred_id' => $deferredId,
            'run_id' => $run,
            'turn_no' => 1,
            'step_id' => 'tools',
            'attempt' => 1,
            'idempotency_key' => 'lifecycle-null-key',
            'tool_call_id' => $toolCallId,
            'tool_name' => 'bash',
            'arguments' => json_encode($arguments, \JSON_THROW_ON_ERROR),
            'order_index' => 0,
            'tool_idempotency_key' => null,
            'mode' => null,
            'timeout_seconds' => null,
            'max_parallelism' => null,
            'assistant_message' => null,
            'arg_schema' => null,
            'tools_ref' => null,
            'invocation_id' => null,
            'controller_run_id' => null,
            'execution_owner_kind' => null,
            'execution_owner_reference_json' => null,
            'original_execution_mode' => null,
            'deadline_metadata_json' => null,
            'origin_wake_generation' => null,
            'root_wake_generation' => null,
            'envelope_fingerprint' => null,
            'caller_disposition' => ToolInvocationCallerDispositionEnum::ForegroundPending->value,
            'execution_observation' => ToolInvocationExecutionObservationEnum::Active->value,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $loaded = $repo->findByRunAndToolCall($run, $toolCallId);
        $this->assertNotNull($loaded);
        $this->assertNull($loaded->envelopeFingerprint);

        $action = new RecordToolInvocationLifecycleDTO(
            $loaded,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $repo->applyLifecycleProjection($action);

        $updated = $repo->findByRunAndToolCall($run, $toolCallId);
        $this->assertNotNull($updated);
        $this->assertNull($updated->envelopeFingerprint);
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $updated->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Active, $updated->executionObservation);

        $reregistered = $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $deferredId,
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'lifecycle-null-key',
            toolCallId: $toolCallId,
            toolName: 'bash',
            arguments: $arguments,
            orderIndex: 0,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::compute(
                $run,
                1,
                'tools',
                $toolCallId,
                'bash',
                'lifecycle-null-key',
                $arguments,
            ),
        ));
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $reregistered->callerDisposition);
        $this->assertNull($reregistered->envelopeFingerprint);
    }

    public function testSuppliedFingerprintMismatchIsRejectedAtRegistration(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('lifecycle fingerprint poison');
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440403',
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'lifecycle-poison-key',
            toolCallId: 'call-lifecycle-poison',
            toolName: 'bash',
            arguments: ['command' => 'sleep 1'],
            orderIndex: 0,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::compute(
                $run,
                1,
                'tools',
                'call-lifecycle-poison',
                'bash',
                'lifecycle-poison-key',
                ['command' => 'sleep 2'],
            ),
        ));
    }

    public function testInvalidLifecycleTransitionIsRejectedWithoutPendingJournal(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('lifecycle invalid transition');
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $active = $container->get(ActiveRunContextInterface::class);
        $correlation = $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440404',
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'lifecycle-invalid-key',
            toolCallId: 'call-lifecycle-invalid',
            toolName: 'bash',
            arguments: ['command' => 'sleep 1'],
            orderIndex: 0,
        ));

        $state = new RunState(runId: $run, status: RunStatus::Running, turnNo: 1, lastSeq: 0, model: 'test-model');
        $active->loadRecovered($state);
        $commit = $this->runCommit($store);
        $beforeSeq = $store->latestSequenceFor($run);

        try {
            $commit->commit(
                $state,
                $state,
                [],
                postCommitActions: [new RecordToolInvocationLifecycleDTO(
                    $correlation,
                    ToolInvocationCallerDispositionEnum::ForegroundResultAccepted,
                    ToolInvocationExecutionObservationEnum::Active,
                )],
            );
            $this->fail('Illegal lifecycle transition must fail before journaling.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unsupported tool invocation lifecycle transition', $exception->getMessage());
        }

        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertSame($beforeSeq, $store->latestSequenceFor($run));
        $unchanged = $repo->findByRunAndToolCall($run, 'call-lifecycle-invalid');
        $this->assertNotNull($unchanged);
        $this->assertSame(ToolInvocationCallerDispositionEnum::ForegroundPending, $unchanged->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Active, $unchanged->executionObservation);
        $this->assertSame(0, $active->requireLoaded($run)->lastSeq);
    }

    public function testDuplicateLifecycleUpdatesAcrossOrdinaryAndAfterTurnActionsAreRejectedWithoutJournal(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('lifecycle duplicate plan');
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $active = $container->get(ActiveRunContextInterface::class);
        $correlation = $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440405',
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'lifecycle-dup-key',
            toolCallId: 'call-lifecycle-dup',
            toolName: 'bash',
            arguments: ['command' => 'sleep 1'],
            orderIndex: 0,
        ));

        $ordinary = new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $afterTurn = new RecordToolInvocationLifecycleDTO(
            new DeferredToolCompletionCorrelation(
                deferredId: $correlation->deferredId,
                runId: $correlation->runId,
                turnNo: $correlation->turnNo,
                stepId: $correlation->stepId,
                attempt: $correlation->attempt,
                idempotencyKey: $correlation->idempotencyKey,
                toolCallId: $correlation->toolCallId,
                toolName: $correlation->toolName,
                arguments: $correlation->arguments,
                orderIndex: $correlation->orderIndex,
                callerDisposition: ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
                executionObservation: ToolInvocationExecutionObservationEnum::Active,
            ),
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::CancelRequested,
        );

        $state = new RunState(runId: $run, status: RunStatus::Running, turnNo: 1, lastSeq: 0, model: 'test-model');
        $active->loadRecovered($state);
        $commit = $this->runCommit($store, new HookDispatcher([
            new class($afterTurn) implements EssentialAfterTurnHookInterface {
                public function __construct(private RecordToolInvocationLifecycleDTO $action)
                {
                }

                public function prepareAfterTurnCommit(AfterTurnCommitHookContext $context, int $predecessorSequence): array
                {
                    return [$this->action];
                }
            },
        ]));
        $beforeSeq = $store->latestSequenceFor($run);

        try {
            $commit->commit($state, $state, [], postCommitActions: [$ordinary]);
            $this->fail('Duplicate lifecycle updates for one invocation must fail before journaling.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('only one lifecycle update', $exception->getMessage());
        }

        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertSame($beforeSeq, $store->latestSequenceFor($run));
        $unchanged = $repo->findByRunAndToolCall($run, 'call-lifecycle-dup');
        $this->assertNotNull($unchanged);
        $this->assertSame(ToolInvocationCallerDispositionEnum::ForegroundPending, $unchanged->callerDisposition);
    }

    public function testDistinctLifecycleInvocationsAreAcceptedInOnePlan(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('lifecycle distinct invocations');
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $active = $container->get(ActiveRunContextInterface::class);
        $first = $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440406',
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'lifecycle-a-key',
            toolCallId: 'call-lifecycle-a',
            toolName: 'bash',
            arguments: ['command' => 'sleep 1'],
            orderIndex: 0,
        ));
        $second = $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440407',
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'lifecycle-b-key',
            toolCallId: 'call-lifecycle-b',
            toolName: 'bash',
            arguments: ['command' => 'sleep 2'],
            orderIndex: 1,
        ));

        $state = new RunState(runId: $run, status: RunStatus::Running, turnNo: 1, lastSeq: 0, model: 'test-model');
        $active->loadRecovered($state);
        $this->runCommit($store)->commit(
            $state,
            $state,
            [],
            postCommitActions: [
                new RecordToolInvocationLifecycleDTO(
                    $first,
                    ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
                    ToolInvocationExecutionObservationEnum::Active,
                ),
                new RecordToolInvocationLifecycleDTO(
                    $second,
                    ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
                    ToolInvocationExecutionObservationEnum::Active,
                ),
            ],
        );

        $this->assertNull($store->verifiedPendingTransition($run));
        $updatedA = $repo->findByRunAndToolCall($run, 'call-lifecycle-a');
        $updatedB = $repo->findByRunAndToolCall($run, 'call-lifecycle-b');
        $this->assertNotNull($updatedA);
        $this->assertNotNull($updatedB);
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $updatedA->callerDisposition);
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $updatedB->callerDisposition);
    }

    public function testAllowedLifecyclePlanRecoversAfterSqlCommitBeforeCanonicalFinalization(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('lifecycle sql then interrupt');
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $active = $container->get(ActiveRunContextInterface::class);
        $correlation = $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440408',
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'lifecycle-interrupt-key',
            toolCallId: 'call-lifecycle-interrupt',
            toolName: 'bash',
            arguments: ['command' => 'sleep 1'],
            orderIndex: 0,
        ));

        $action = new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        );
        $state = new RunState(runId: $run, status: RunStatus::Running, turnNo: 1, lastSeq: 0, model: 'test-model');
        $active->loadRecovered($state);

        $interruptStore = new class($store) implements PreparedTransitionEventStoreInterface {
            public function __construct(private PreparedTransitionEventStoreInterface $inner)
            {
            }

            public function appendTransition(array $events, array $work): array
            {
                return $this->inner->appendTransition($events, $work);
            }

            public function verifiedPendingTransition(string $runId): ?VerifiedTransitionDTO
            {
                return $this->inner->verifiedPendingTransition($runId);
            }

            public function verifiedPendingBatch(string $runId, string $identity): array
            {
                return $this->inner->verifiedPendingBatch($runId, $identity);
            }

            public function finalizeVerifiedTransition(string $runId, string $identity): void
            {
                throw new \RuntimeException('Injected finalize failure after lifecycle SQL.');
            }

            public function assertTransitionReady(string $runId): void
            {
                $this->inner->assertTransitionReady($runId);
            }

            public function latestSequenceFor(string $runId): ?int
            {
                return $this->inner->latestSequenceFor($runId);
            }

            public function firstFor(string $runId): ?RunEvent
            {
                return $this->inner->firstFor($runId);
            }

            public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
            {
                return $this->inner->rangeFor($runId, $startSeq, $endSeq);
            }

            public function reverseFor(string $runId): iterable
            {
                return $this->inner->reverseFor($runId);
            }

            public function allFor(string $runId): array
            {
                return $this->inner->allFor($runId);
            }
        };

        $bus = $container->get('agent.command.bus');
        $finalizer = new TransitionFinalizer(
            $interruptStore,
            new StepDispatcher($bus, $bus, new \Ineersa\AgentCore\Tests\Support\TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher()),
            new LocalMetadataCoordinator(
                $container->get(ApplicationDbTransactionInterface::class),
                $container->get(ToolBatchStoreInterface::class),
                $container->get(CommandStoreInterface::class),
                $repo,
            ),
            new TransitionPlanFactory(),
            $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
        );
        $commit = new RunCommit(
            activeRunContext: $active,
            eventStore: $interruptStore,
            logger: new \Ineersa\AgentCore\Tests\Support\TestLogger(),
            finalizer: $finalizer,
            hookDispatcher: null,
            tracer: null,
            actionValidator: $container->get(\Ineersa\AgentCore\Application\Handler\CoordinationActionValidator::class),
        );

        try {
            $commit->commit($state, $state, [], postCommitActions: [$action]);
            $this->fail('Finalize interruption must retain the pending transition.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected finalize failure after lifecycle SQL.', $exception->getMessage());
        }

        $this->assertNotNull($store->verifiedPendingTransition($run));
        $settled = $repo->findByRunAndToolCall($run, 'call-lifecycle-interrupt');
        $this->assertNotNull($settled);
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $settled->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Active, $settled->executionObservation);

        $this->recovery($bus)->recover($run);
        $this->assertNull($store->verifiedPendingTransition($run));
        $recovered = $repo->findByRunAndToolCall($run, 'call-lifecycle-interrupt');
        $this->assertNotNull($recovered);
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $recovered->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Active, $recovered->executionObservation);
        $this->assertSame('pending', $repo->status($correlation->deferredId));
    }

    public function testTerminalAcceptanceMarksCompletedAndRecoversAfterInterruptedFinalization(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('lifecycle terminal acceptance');
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);
        $correlation = $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440501',
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'lifecycle-terminal-key',
            toolCallId: 'call-lifecycle-terminal',
            toolName: 'fork',
            arguments: ['prompt' => 'inspect'],
            orderIndex: 0,
        ));

        $completionId = ToolInvocationAcceptedCompletionIdentity::completionIdFromCorrelation($correlation);
        $fingerprint = ToolInvocationAcceptedCompletionIdentity::resultFingerprint([
            'tool_call_id' => $correlation->toolCallId,
            'content' => [['type' => 'text', 'text' => 'done']],
        ]);
        $action = new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::ForegroundResultAccepted,
            ToolInvocationExecutionObservationEnum::Terminal,
            $completionId,
            $fingerprint,
        );

        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $bus = $container->get('agent.command.bus');
        $active = $container->get(ActiveRunContextInterface::class);
        $state = new RunState(runId: $run, status: RunStatus::Running, turnNo: 1, lastSeq: 0, model: 'test-model');
        $active->loadRecovered($state);

        $interruptStore = new class($store) implements PreparedTransitionEventStoreInterface {
            public function __construct(private PreparedTransitionEventStoreInterface $inner)
            {
            }

            public function appendTransition(array $events, array $work): array
            {
                return $this->inner->appendTransition($events, $work);
            }

            public function verifiedPendingTransition(string $runId): ?VerifiedTransitionDTO
            {
                return $this->inner->verifiedPendingTransition($runId);
            }

            public function verifiedPendingBatch(string $runId, string $identity): array
            {
                return $this->inner->verifiedPendingBatch($runId, $identity);
            }

            public function finalizeVerifiedTransition(string $runId, string $identity): void
            {
                throw new \RuntimeException('Injected finalize failure after terminal acceptance SQL.');
            }

            public function assertTransitionReady(string $runId): void
            {
                $this->inner->assertTransitionReady($runId);
            }

            public function latestSequenceFor(string $runId): ?int
            {
                return $this->inner->latestSequenceFor($runId);
            }

            public function firstFor(string $runId): ?RunEvent
            {
                return $this->inner->firstFor($runId);
            }

            public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
            {
                return $this->inner->rangeFor($runId, $startSeq, $endSeq);
            }

            public function reverseFor(string $runId): iterable
            {
                return $this->inner->reverseFor($runId);
            }

            public function allFor(string $runId): array
            {
                return $this->inner->allFor($runId);
            }
        };
        $finalizer = new TransitionFinalizer(
            $interruptStore,
            new StepDispatcher($bus, $bus, new \Ineersa\AgentCore\Tests\Support\TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher()),
            new LocalMetadataCoordinator(
                $container->get(ApplicationDbTransactionInterface::class),
                $container->get(ToolBatchStoreInterface::class),
                $container->get(CommandStoreInterface::class),
                $repo,
            ),
            new TransitionPlanFactory(),
            $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
        );
        $commit = new RunCommit(
            activeRunContext: $active,
            eventStore: $interruptStore,
            logger: new \Ineersa\AgentCore\Tests\Support\TestLogger(),
            finalizer: $finalizer,
            hookDispatcher: null,
            tracer: null,
            actionValidator: $container->get(\Ineersa\AgentCore\Application\Handler\CoordinationActionValidator::class),
        );

        try {
            $commit->commit($state, $state, [], postCommitActions: [$action]);
            $this->fail('Finalize interruption must retain the pending transition.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected finalize failure after terminal acceptance SQL.', $exception->getMessage());
        }

        $this->assertNotNull($store->verifiedPendingTransition($run));
        $settled = $repo->findByRunAndToolCall($run, 'call-lifecycle-terminal');
        $this->assertNotNull($settled);
        $this->assertSame(ToolInvocationCallerDispositionEnum::ForegroundResultAccepted, $settled->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Terminal, $settled->executionObservation);
        $this->assertSame($completionId, $settled->acceptedCompletionId);
        $this->assertSame($fingerprint, $settled->acceptedResultFingerprint);
        $this->assertSame('completed', $repo->status($correlation->deferredId));

        $this->recovery($bus)->recover($run);
        $this->assertNull($store->verifiedPendingTransition($run));
        $recovered = $repo->findByRunAndToolCall($run, 'call-lifecycle-terminal');
        $this->assertNotNull($recovered);
        $this->assertSame(ToolInvocationCallerDispositionEnum::ForegroundResultAccepted, $recovered->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Terminal, $recovered->executionObservation);
        $this->assertSame($completionId, $recovered->acceptedCompletionId);
        $this->assertSame('completed', $repo->status($correlation->deferredId));

        // Matching duplicate acceptance is an exact-target no-op.
        $repo->applyLifecycleProjection($action);
        $this->assertSame('completed', $repo->status($correlation->deferredId));
    }

    private function recovery(MessageBusInterface $bus): PendingTransitionRecovery
    {
        $container = self::getContainer();
        $store = $container->get(PreparedTransitionEventStoreInterface::class);

        return new PendingTransitionRecovery(
            $store,
            $container->get(ActiveRunContextInterface::class),
            TestTransitionFinalizerFactory::create(
                $store,
                new StepDispatcher($bus, $bus, new \Ineersa\AgentCore\Tests\Support\TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher()),
                batches: $container->get(ToolBatchStoreInterface::class),
                commands: $container->get(CommandStoreInterface::class),
                deferredCompletions: $container->get(DeferredToolCompletionRepositoryInterface::class),
            ),
            $container->get(\Ineersa\AgentCore\Application\Handler\CoordinationActionValidator::class),
        );
    }

    private function runCommit(PreparedTransitionEventStoreInterface $store, ?HookDispatcher $hooks = null): RunCommit
    {
        $container = self::getContainer();
        $bus = $container->get('agent.command.bus');

        return new RunCommit(
            activeRunContext: $container->get(ActiveRunContextInterface::class),
            eventStore: $store,
            logger: new \Ineersa\AgentCore\Tests\Support\TestLogger(),
            finalizer: new TransitionFinalizer(
                $store,
                new StepDispatcher($bus, $bus, new \Ineersa\AgentCore\Tests\Support\TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher()),
                new LocalMetadataCoordinator(
                    $container->get(ApplicationDbTransactionInterface::class),
                    $container->get(ToolBatchStoreInterface::class),
                    $container->get(CommandStoreInterface::class),
                    $container->get(DeferredToolCompletionRepositoryInterface::class),
                ),
                new TransitionPlanFactory(),
                $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
            ),
            hookDispatcher: $hooks,
            tracer: null,
            actionValidator: $container->get(\Ineersa\AgentCore\Application\Handler\CoordinationActionValidator::class),
        );
    }
}
