<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
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
}
