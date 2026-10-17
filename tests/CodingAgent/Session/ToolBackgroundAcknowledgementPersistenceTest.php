<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationBackgroundReadiness;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[Group('db')]
final class ToolBackgroundAcknowledgementPersistenceTest extends IsolatedKernelTestCase
{
    public function testPreparedAcknowledgementCommitsTogetherAndRecoversAfterSqlBeforeFinalization(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('bg ack persistence');
        /** @var ToolBatchStoreInterface $batches */
        $batches = $container->get(ToolBatchStoreInterface::class);
        /** @var DeferredToolCompletionRepositoryInterface $repo */
        $repo = $container->get(DeferredToolCompletionRepositoryInterface::class);
        $collector = new ToolBatchCollector($batches, 4, $repo);

        $call = new ExecuteToolCall($run, 1, 'tools', 1, 'ack-key', 'call-ack', 'bash', ['command' => 'sleep 30'], 0, mode: 'sequential');
        $register = $collector->prepareRegistration($run, 1, 'tools', [$call]);
        $transition = new VerifiedTransitionDTO(Uuid::v7()->toRfc4122(), ['run_id' => $run, 'actions' => [$register]]);
        $batches->prepareChanges([$register], $transition)();

        $owner = new ToolInvocationOwnerReference('bash', 'record-ack', 4242, '/tmp/ack.log');
        $base = new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440700',
            runId: $run,
            turnNo: 1,
            stepId: 'tools',
            attempt: 1,
            idempotencyKey: 'ack-key',
            toolCallId: 'call-ack',
            toolName: 'bash',
            arguments: ['command' => 'sleep 30'],
            orderIndex: 0,
            mode: 'sequential',
            invocationId: 'inv-ack',
            controllerRunId: $run,
            executionOwnerKind: 'bash',
            executionOwnerReference: $owner,
            originalExecutionMode: 'sequential',
        );
        $correlation = $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: $base->deferredId,
            runId: $base->runId,
            turnNo: $base->turnNo,
            stepId: $base->stepId,
            attempt: $base->attempt,
            idempotencyKey: $base->idempotencyKey,
            toolCallId: $base->toolCallId,
            toolName: $base->toolName,
            arguments: $base->arguments,
            orderIndex: $base->orderIndex,
            mode: $base->mode,
            invocationId: $base->invocationId,
            controllerRunId: $base->controllerRunId,
            executionOwnerKind: $base->executionOwnerKind,
            executionOwnerReference: $base->executionOwnerReference,
            originalExecutionMode: $base->originalExecutionMode,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::fromCorrelation($base),
        ));

        $prepared = $collector->prepareBackgroundAcknowledgement(new ToolInvocationBackgroundReadiness(
            $correlation->runId,
            $correlation->turnNo,
            $correlation->stepId,
            $correlation->toolCallId,
            $correlation->toolName,
            $owner,
            $correlation->invocationId,
            $correlation->controllerRunId,
            $correlation->envelopeFingerprint,
        ));
        $this->assertTrue($prepared->accepted);
        $this->assertNotNull($prepared->batchAction);
        $this->assertNotNull($prepared->lifecycleAction);

        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => $prepared->localActions]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);

        $coordinator = new LocalMetadataCoordinator(
            $container->get(ApplicationDbTransactionInterface::class),
            $batches,
            $container->get(CommandStoreInterface::class),
            $repo,
            self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface::class),
        );
        $coordinator->apply(new TransitionPlan($run, $pending, $prepared->localActions, [], []));

        $afterSql = $repo->findByRunAndToolCall($run, 'call-ack');
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $afterSql?->callerDisposition);
        $batch = $batches->load($run, 1, 'tools');
        $this->assertNotNull($batch);
        $this->assertArrayHasKey('call-ack', $batch->results);
        $this->assertSame('pending', $repo->status($correlation->deferredId));
        $this->assertNotNull($store->verifiedPendingTransition($run));

        $bus = $container->get('agent.command.bus');
        $this->recovery($bus)->recover($run);
        $this->assertNull($store->verifiedPendingTransition($run));

        $recovered = $repo->findByRunAndToolCall($run, 'call-ack');
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $recovered?->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Active, $recovered?->executionObservation);
        $this->assertSame('pending', $repo->status($correlation->deferredId));
        $this->assertSame('record-ack', $recovered?->executionOwnerReference?->recordId);

        $again = $collector->prepareBackgroundAcknowledgement(new ToolInvocationBackgroundReadiness(
            $correlation->runId,
            $correlation->turnNo,
            $correlation->stepId,
            $correlation->toolCallId,
            $correlation->toolName,
            $owner,
            $correlation->invocationId,
            $correlation->controllerRunId,
            $correlation->envelopeFingerprint,
        ));
        $this->assertTrue($again->accepted);
        $this->assertTrue($again->duplicate);
        $this->assertSame([], $again->localActions);
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
