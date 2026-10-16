<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
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
