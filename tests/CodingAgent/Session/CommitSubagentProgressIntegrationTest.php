<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Message\CommitSubagentProgress;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareEventStore;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Stream\StreamingCommittedRuntimeEventStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class CommitSubagentProgressIntegrationTest extends PerMethodIsolatedKernelTestCase
{
    private RecordingProgressRuntimeEventSink $sink;

    public function testConfiguredBusDefersCanonicalAndLiveProgressUntilOwnerConsumptionWithoutReplay(): void
    {
        $this->seed();
        $bus = self::getContainer()->get('agent.command.bus');
        $command = $this->command(1);
        $queued = $bus->dispatch($command);
        $store = self::getContainer()->get(EventStoreInterface::class);
        $this->assertNull($store->latestSequenceFor('parent'));
        $this->assertSame([], $this->sink->emitted);
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $this->assertSame(0, $repo->findByLifecycleId('batch')->deliveredProgressRevision);
        // A projection version change between production and consumption is legitimate.
        $row = $repo->findEntityByLifecycleId('batch');
        ++$row->projectionVersion;
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();
        $bus->dispatch($queued->with(new ReceivedStamp('run_control')));
        $this->assertSame(1, $store->latestSequenceFor('parent'));
        $this->assertCount(1, $this->sink->emitted);
        $this->assertSame(1, $this->sink->emitted[0]->seq);
        $this->assertSame('call', $this->sink->emitted[0]->payload['tool_call_id']);
        $active = self::getContainer()->get(ActiveRunContextInterface::class);
        $this->assertSame(1, $active->requireLoaded('parent')->lastSeq);
        $this->assertSame(1, $repo->findByLifecycleId('batch')->deliveredProgressRevision);
        $sent = self::getContainer()->get('messenger.transport.run_control')->getSent();
        $this->assertCount(2, $sent);
        $this->assertInstanceOf(CommitSubagentProgress::class, $sent[0]->getMessage());
        $this->assertInstanceOf(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleMessage::class, $sent[1]->getMessage());
        $bus->dispatch($queued->with(new ReceivedStamp('run_control')));
        $this->assertSame(1, $store->latestSequenceFor('parent'));
        $this->assertSame(1, $active->requireLoaded('parent')->lastSeq);
        $this->assertCount(1, $this->sink->emitted);
    }

    public function testStaleRevisionDoesNotAppendAndSchedulesCurrentLifecycleDelivery(): void
    {
        $this->seed(2);
        $this->consume($this->command(1));
        $this->assertNull(self::getContainer()->get(EventStoreInterface::class)->latestSequenceFor('parent'));
        $this->assertSame(0, self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByLifecycleId('batch')->deliveredProgressRevision);
        $sent = self::getContainer()->get('messenger.transport.run_control')->getSent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleMessage::class, $sent[0]->getMessage());
    }

    public function testMismatchedInvocationFailsBeforeCanonicalAppend(): void
    {
        $this->seed();
        $this->expectExceptionMessage('Subagent progress invocation identity mismatch.');
        try {
            $this->consume(new CommitSubagentProgress('parent', 1, 'batch', 'other-call', 0, 1, ['status' => 'completed']));
        } finally {
            $this->assertNull(self::getContainer()->get(EventStoreInterface::class)->latestSequenceFor('parent'));
        }
    }

    public function testForcedInterruptionCommitsOnceAndNormalQueuedProgressCannotOvertakeIt(): void
    {
        $this->seed();
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $row = $repo->findEntityByLifecycleId('batch');
        $row->interruptionKind = DeferredSubagentInterruptionKindEnum::ParentCancelled;
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();
        // Cancelling an approval wait can terminalize and clear the tool map
        // before the interrupted child's final progress reaches the owner.
        $active = self::getContainer()->get(ActiveRunContextInterface::class);
        $active->loadRecovered($active->requireLoaded('parent')->with(['status' => \Ineersa\AgentCore\Domain\Run\RunStatus::Cancelled, 'pendingToolCalls' => []]));
        $forced = new CommitSubagentProgress('parent', 1, 'batch', 'call', 0, 1, ['status' => 'cancelled'], 'parent_cancelled');
        $this->consume($forced);
        $this->assertNotNull($repo->findByLifecycleId('batch')->interruptionProgressEnqueuedAt);
        $this->consume($forced);
        $this->consume($this->command(1));
        $this->assertSame(1, self::getContainer()->get(EventStoreInterface::class)->latestSequenceFor('parent'));
        $this->assertCount(1, $this->sink->emitted);
    }

    public function testAppendFailureDoesNotAdvanceDeliveryOrOwnerSequence(): void
    {
        $this->seed();
        $store = $this->createMock(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        $store->expects($this->once())->method('appendTransition')->willThrowException(new \RuntimeException('canonical append failed'));
        $bus = new \Ineersa\AgentCore\Tests\Support\TestMessageBus();
        $active = self::getContainer()->get(ActiveRunContextInterface::class);
        $dispatcher = new \Ineersa\AgentCore\Application\Handler\StepDispatcher($bus, $bus);
        $commit = new \Ineersa\AgentCore\Application\Pipeline\RunCommit($active, $store, $dispatcher,
            new \Ineersa\AgentCore\Tests\Support\TestLogger(), new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector(), new \Ineersa\AgentCore\Tests\Support\TestToolExecutionAuthorization(), new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(), new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore()), actionValidator: self::getContainer()->get(\Ineersa\AgentCore\Application\Handler\CoordinationActionValidator::class));
        $processor = new \Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor($active,
            self::getContainer()->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class), $commit,
            [new \Ineersa\CodingAgent\Application\Pipeline\CommitSubagentProgressHandler(self::getContainer()->get(DeferredSubagentBatchRepository::class), $bus)]);
        $this->expectExceptionMessage('canonical append failed');
        try {
            $processor->process('command.subagent_progress', $this->command(1));
        } finally {
            $this->assertSame(0, self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByLifecycleId('batch')->deliveredProgressRevision);
            $this->assertSame(0, self::getContainer()->get(ActiveRunContextInterface::class)->requireLoaded('parent')->lastSeq);
            $this->assertSame([], $this->sink->emitted);
        }
    }

    public function testPreparedApplicationProgressActionRecoversWithoutRebuildingEvents(): void
    {
        $this->seed();
        $container = self::getContainer();
        $state = $container->get(ActiveRunContextInterface::class)->requireLoaded('parent');
        $prepared = $container->get(\Ineersa\CodingAgent\Application\Pipeline\CommitSubagentProgressHandler::class)->handle($this->command(1), $state);
        $store = $container->get(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        $store->appendTransition($prepared->events, ['run_id' => 'parent', 'predecessor_seq' => 0, 'actions' => $prepared->postCommitActions]);
        $repository = $container->get(DeferredSubagentBatchRepository::class);
        $this->assertSame(0, $repository->findByLifecycleId('batch')->deliveredProgressRevision);
        $this->assertNull($store->latestSequenceFor('parent'));
        $recovery = $container->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class);
        $recovery->recover('parent');
        $this->assertSame(1, $repository->findByLifecycleId('batch')->deliveredProgressRevision);
        $this->assertSame(1, $store->latestSequenceFor('parent'));
        $this->assertNull($store->verifiedPendingTransition('parent'));
        $sent = $container->get('messenger.transport.run_control')->getSent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleMessage::class, $sent[0]->getMessage());
        $recovery->recover('parent');
        $this->assertCount(1, $container->get('messenger.transport.run_control')->getSent());
    }

    public function testOldParentTurnCannotPublishIntoCurrentInvocation(): void
    {
        $this->seed();
        self::getContainer()->get(ActiveRunContextInterface::class)->loadRecovered(RunStateBuilder::running('parent')->withTurnNo(2)->withPendingToolCalls(['call' => false])->build());
        $this->consume($this->command(1));
        $this->assertNull(self::getContainer()->get(EventStoreInterface::class)->latestSequenceFor('parent'));
        $this->assertSame([], $this->sink->emitted);
        $this->assertSame(1, self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByLifecycleId('batch')->deliveredProgressRevision);
    }

    public function testResolvedToolCannotReceiveNormalProgressWhileSiblingsRemain(): void
    {
        $this->seed();
        $active = self::getContainer()->get(ActiveRunContextInterface::class);
        $active->loadRecovered($active->requireLoaded('parent')->with(['pendingToolCalls' => ['call' => true, 'sibling' => false]]));
        $this->consume($this->command(1));
        $this->assertNull(self::getContainer()->get(EventStoreInterface::class)->latestSequenceFor('parent'));
        $this->assertSame([], $this->sink->emitted);
        $this->assertSame(1, self::getContainer()->get(DeferredSubagentBatchRepository::class)->findByLifecycleId('batch')->deliveredProgressRevision);
    }

    protected function afterKernelBoot(): void
    {
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->expects($this->never())->method('rebuildIfStale');
        $rebuilder->expects($this->never())->method('rebuildAtPosition');
        self::getContainer()->set(RunStateRebuilderInterface::class, $rebuilder);
        $this->sink = new RecordingProgressRuntimeEventSink();
        self::getContainer()->set(StreamingCommittedRuntimeEventStore::class, new StreamingCommittedRuntimeEventStore(
            self::getContainer()->get(ChildAwareEventStore::class), self::getContainer()->get(RuntimeEventMapper::class), $this->sink, true,
        ));
    }

    private function command(int $revision): CommitSubagentProgress
    {
        return new CommitSubagentProgress('parent', 1, 'batch', 'call', 0, $revision, ['mode' => 'single', 'status' => 'completed', 'agent_name' => 'scout', 'artifact_id' => 'artifact', 'agent_run_id' => 'child']);
    }

    private function consume(CommitSubagentProgress $command): void
    {
        self::getContainer()->get('agent.command.bus')->dispatch($command, [new ReceivedStamp('run_control')]);
    }

    private function seed(int $revision = 1): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $repo->reserveBatch('batch', 'parent', 1, 'call', 0, ChildRunBatchExecutionModeEnum::Single, 1, new \DateTimeImmutable('+1 hour'), [
            ['batchIndex' => 1, 'childRunId' => 'child', 'artifactId' => 'artifact', 'agentName' => 'scout', 'task' => 'task', 'launchModel' => 'model', 'launchReasoning' => 'medium'],
        ]);
        $row = $repo->findEntityByLifecycleId('batch');
        $row->aggregateProgressRevision = $revision;
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();
        self::getContainer()->get(ActiveRunContextInterface::class)->loadRecovered(RunStateBuilder::running('parent')->withTurnNo(1)->withPendingToolCalls(['call' => false])->build());
    }
}

/** @internal */
final class RecordingProgressRuntimeEventSink implements RuntimeEventSinkInterface
{
    /** @var list<RuntimeEvent> */
    public array $emitted = [];

    public function emit(RuntimeEvent $event): void
    {
        $this->emitted[] = $event;
    }
}
