<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\RunContextNotLoadedException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class OwnerRunInitializationMiddlewareTest extends PerMethodIsolatedKernelTestCase
{
    public function testConsumedResumeRedeliveryDoesNotReapplyOrDisturbLaterWork(): void
    {
        $container = self::getContainer();
        $run = $this->reserve();
        $bus = $container->get('agent.command.bus');
        $receive = static fn (object $message) => $bus->dispatch(new Envelope($message, [new ReceivedStamp('run_control')]));
        $receive($this->start($run));
        $events = $container->get(EventStoreInterface::class);
        $commands = $container->get(CommandStoreInterface::class);
        $command = static fn (string $key, bool $tagged = true): ApplyCommand => new ApplyCommand(
            $run, 0, $key, 1, $key, 'follow_up',
            ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $key]], 'metadata' => $tagged ? ['agent_task' => ['artifact_id' => 'agent_resume_target']] : []]],
        );
        $drain = function (string $key) use ($container, $run): void {
            // Exercise the real mailbox boundary without invoking an LLM worker.
            $state = $this->registry()->requireLoaded($run);
            $result = $container->get(AdvanceRunHandler::class)->handle(
                new AdvanceRun($run, $state->turnNo, $key, 1, $key),
                $state->with(['currentOperation' => null, 'activeStepId' => null]),
            );
            $container->get(PreparedTransitionEventStoreInterface::class)->appendTransition($result->events, ['run_id' => $run, 'predecessor_seq' => $state->lastSeq, 'actions' => $result->postCommitActions]);
            $container->get(PendingTransitionRecovery::class)->recover($run);
            $this->registry()->release($run);
        };
        $receive($command('resume-first'));
        $drain('drain-first');
        $this->assertSame([], $commands->pending($run));
        $before = iterator_to_array($events->rangeFor($run, 1, \PHP_INT_MAX));
        $receive($command('resume-first'));
        $downstream = $this->createMock(MiddlewareInterface::class);
        $downstream->expects($this->never())->method('handle');
        $container->get(OwnerRunInitializationMiddleware::class)->handle(
            new Envelope($command('resume-first'), [new ReceivedStamp('run_control')]),
            new StackMiddleware($downstream),
        );
        $this->assertEquals($before, iterator_to_array($events->rangeFor($run, 1, \PHP_INT_MAX)));
        $this->assertSame([], $commands->pending($run));

        $receive($command('resume-later'));
        $pending = $commands->pending($run);
        $this->assertCount(1, $pending);
        $before = iterator_to_array($events->rangeFor($run, 1, \PHP_INT_MAX));
        $state = $this->registry()->requireLoaded($run);
        $receive($command('resume-first'));
        $this->assertSame($state, $this->registry()->requireLoaded($run));
        $this->assertEquals($pending, $commands->pending($run));
        $this->assertEquals($before, iterator_to_array($events->rangeFor($run, 1, \PHP_INT_MAX)));
        $drain('drain-later');

        $receive($command('ordinary', false));
        $drain('drain-ordinary');
        $this->assertSame([], $commands->pending($run));
        $receive($command('ordinary', false));
        $this->assertCount(1, $commands->pending($run), 'Untagged completed command IDs remain reusable.');
        $all = iterator_to_array($events->rangeFor($run, 1, \PHP_INT_MAX));
        $applied = array_values(array_filter($all, static fn (RunEvent $event): bool => 'agent_command_applied' === $event->type));
        $this->assertSame(['resume-first', 'resume-later', 'ordinary'], array_column(array_map(static fn (RunEvent $event): array => $event->payload, $applied), 'idempotency_key'));
        $this->assertCount(3, array_filter($all, static fn (RunEvent $event): bool => 'turn_advanced' === $event->type));
    }

    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function testConsumedResumeReleasesReconstructedTerminalChildOnly(bool $terminal): void
    {
        $container = self::getContainer();
        $parent = $this->reserve();
        $run = 'resume-recovered-child';
        $container->get(AgentArtifactRegistry::class)->create($parent, 'agent_resume_target', $run, 'scout', AgentArtifactKindEnum::Subagent);
        $bus = $container->get('agent.command.bus');
        $receive = static fn (object $message) => $bus->dispatch(new Envelope($message, [new ReceivedStamp('run_control')]));
        $receive($this->childStart($run, $parent));
        $resume = static fn (string $key): ApplyCommand => new ApplyCommand($run, 0, $key, 1, $key, 'follow_up', [
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'task']], 'metadata' => ['agent_task' => ['artifact_id' => 'agent_resume_target']]],
        ]);
        $receive($resume('same-resume'));
        $state = $this->registry()->requireLoaded($run);
        $result = $container->get(AdvanceRunHandler::class)->handle(new AdvanceRun($run, $state->turnNo, 'drain', 1, 'drain'), $state->with(['currentOperation' => null, 'activeStepId' => null]));
        $container->get(PreparedTransitionEventStoreInterface::class)->appendTransition($result->events, ['run_id' => $run, 'predecessor_seq' => $state->lastSeq, 'actions' => $result->postCommitActions]);
        $container->get(PendingTransitionRecovery::class)->recover($run);
        $events = $container->get(EventStoreInterface::class);
        if ($terminal) {
            PreparedEventStoreSeeder::append($events, RunEvent::forAppend($run, 0, 'agent_end', ['reason' => 'completed']));
        }
        $this->registry()->release($run);
        $before = iterator_to_array($events->rangeFor($run, 1, \PHP_INT_MAX));
        $downstream = $this->createMock(MiddlewareInterface::class);
        $downstream->expects($this->never())->method('handle');
        $container->get(OwnerRunInitializationMiddleware::class)->handle(new Envelope($resume('same-resume'), [new ReceivedStamp('run_control')]), new StackMiddleware($downstream));
        $this->assertEquals($before, iterator_to_array($events->rangeFor($run, 1, \PHP_INT_MAX)));
        $this->assertSame([], $container->get(CommandStoreInterface::class)->pending($run));
        if ($terminal) {
            $this->expectException(RunContextNotLoadedException::class);
            $this->registry()->requireLoaded($run);
        } else {
            $this->assertSame(RunStatus::Running, $this->registry()->requireLoaded($run)->status);
            $receive($resume('distinct-resume'));
            $this->assertCount(1, $container->get(CommandStoreInterface::class)->pending($run));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('launchKinds')]
    public function testEnqueuedChildStartIsAcceptedByFreshOwnerDirectory(AgentArtifactKindEnum $kind): void
    {
        $container = self::getContainer();
        $parent = $this->reserve();
        $identity = new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO($parent, 'child-fresh', 'agent_fresh', 'scout', 'task', $kind);
        $lifecycle = $container->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService::class);
        $lifecycle->reservePending($identity);
        $input = new \Ineersa\AgentCore\Domain\Run\StartRunInput('instructions', [], $identity->childRunId, new RunMetadata(session: ['kind' => 'agent_child', 'parent_run_id' => $parent], model: 'test-model'));
        $container->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchRuntimeStartService::class)->startPrepared($parent, 'launch-call', $identity, new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO($identity, $input));
        $queued = $container->get('messenger.transport.run_control')->getSent()[0];
        $this->assertInstanceOf(StartRun::class, $queued->getMessage());
        $artifacts = $container->get(AgentArtifactRegistry::class);
        $freshDirectory = new \Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory($container->get(HatfieldSessionStore::class), $artifacts, new \Psr\Log\NullLogger());
        $this->assertSame(\Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum::Pending, $freshDirectory->locate($identity->childRunId)->status);
        $events = $container->get(EventStoreInterface::class);
        $this->assertNull($events->latestSequenceFor($identity->childRunId));
        self::$kernel->shutdown();
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $container = self::getContainer();
        $freshRegistry = $container->get(ActiveRunContextInterface::class);
        $events = $container->get(EventStoreInterface::class);
        $artifacts = $container->get(AgentArtifactRegistry::class);
        $container->get('agent.command.bus')->dispatch($queued->with(new ReceivedStamp('run_control')));
        $this->assertSame(RunStatus::Running, $freshRegistry->requireLoaded($identity->childRunId)->status);
        $this->assertSame($parent, $freshRegistry->requireLoaded($identity->childRunId)->parentRunId);
        $this->assertSame(1, $events->latestSequenceFor($identity->childRunId));
        $this->assertSame(\Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum::Running, $artifacts->get($parent, $identity->artifactId)->status);
        $container->get('agent.command.bus')->dispatch($queued->with(new ReceivedStamp('run_control')));
        $this->assertSame(1, $events->latestSequenceFor($identity->childRunId), 'Launch redelivery must not append a second StartRun.');
    }

    public static function launchKinds(): iterable
    {
        yield 'ordinary child' => [AgentArtifactKindEnum::Subagent];
        yield 'profiled fork shared runtime start' => [AgentArtifactKindEnum::Fork];
    }

    public function testProducerDispatchDoesNotAdmitOrRecoverReservedRun(): void
    {
        $run = $this->reserve();
        self::getContainer()->get('agent.command.bus')->dispatch($this->start($run));
        $this->expectException(RunContextNotLoadedException::class);
        $this->registry()->requireLoaded($run);
    }

    public function testExplicitReservedStartCreatesQueuedStateOnlyAtOwnerEntry(): void
    {
        $run = $this->reserve();
        $this->consume($this->start($run));
        $this->assertSame(RunStatus::Queued, $this->registry()->requireLoaded($run)->status);
        $this->assertNull(self::getContainer()->get(EventStoreInterface::class)->latestSequenceFor($run));
    }

    public function testOrdinaryFollowUpCannotCreateReservedButEmptyRun(): void
    {
        $run = $this->reserve();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires canonical history');
        $this->consume(new ApplyCommand($run, 0, 'follow', 1, 'follow', 'follow_up'));
    }

    public function testUnknownRunCannotBeAdmittedEvenByStart(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unregistered run');
        $this->consume($this->start('unregistered'));
    }

    public function testPriorOperationalEvidenceWithMissingArchiveCannotBecomeQueued(): void
    {
        $run = $this->reserve();
        self::getContainer()->get(RunOperationalProjectionRepository::class)->replace(new RunState($run, RunStatus::Completed, lastSeq: 12));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('prior execution evidence');
        $this->consume($this->start($run));
    }

    public function testReservedChildCancellationPreservesParentIdentityBeforeStart(): void
    {
        $parent = $this->reserve();
        $entry = self::getContainer()->get(AgentArtifactRegistry::class)->create($parent, 'agent_reserved', 'reserved-child', 'scout', AgentArtifactKindEnum::Subagent);
        $this->consume(new ApplyCommand($entry->agentRunId, 0, 'cancel', 1, 'cancel', 'cancel'));
        $state = $this->registry()->requireLoaded($entry->agentRunId);
        $this->assertSame($parent, $state->parentRunId);
        $projection = self::getContainer()->get(RunOperationalProjectionRepository::class)->find($entry->agentRunId);
        $this->assertSame($parent, $projection->parentRunId);
        $this->assertSame($parent, $projection->ownerSessionId);
    }

    public function testRecoveryOccursOnceUntilExplicitRelease(): void
    {
        $run = $this->reserve();
        PreparedEventStoreSeeder::append(self::getContainer()->get(EventStoreInterface::class), RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'test-model']]]));
        $this->consume($this->start($run));
        $first = $this->registry()->requireLoaded($run);
        $this->assertSame(1, $first->lastSeq);
        $this->consume($this->start($run));
        $this->assertSame($first, $this->registry()->requireLoaded($run));
        $this->registry()->release($run);
        $this->consume($this->start($run));
        $this->assertNotSame($first, $this->registry()->requireLoaded($run));
        $this->assertSame(1, $this->registry()->requireLoaded($run)->lastSeq);
    }

    public function testSynchronousOwnerRedeliveryRecoversAfterPublicationFailureWithoutDuplicateStart(): void
    {
        $run = $this->reserve();
        $this->registry()->createNew($run);
        $events = self::getContainer()->get(EventStoreInterface::class);
        PreparedEventStoreSeeder::append($events, RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'test-model']], 'step_id' => 'start']));
        try {
            $this->registry()->replaceCurrent(new RunState($run, RunStatus::Running, lastSeq: 1, activeStepId: str_repeat('x', 256)));
            $this->fail('Projection validation must fail after the canonical append.');
        } catch (\Symfony\Component\Validator\Exception\ValidationFailedException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $sync = new \Symfony\Component\Messenger\Transport\Sync\SyncTransport(self::getContainer()->get('agent.command.bus'));
        $sync->send(new Envelope($this->start($run), [new \Symfony\Component\Messenger\Stamp\SentStamp($sync::class, 'run_control')]));
        $state = $this->registry()->requireLoaded($run);
        $this->assertSame(RunStatus::Running, $state->status);
        $this->assertSame('test-model', $state->model);
        $this->assertSame(1, $state->lastSeq);
        $this->assertCount(1, iterator_to_array($events->rangeFor($run, 1, \PHP_INT_MAX)));
        $sent = self::getContainer()->get('messenger.transport.run_control')->getSent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(AdvanceRun::class, $sent[0]->getMessage());
    }

    public function testEmptyRecoveryProductIsRejectedWithoutPublishingQueuedState(): void
    {
        $run = $this->reserve();
        $events = self::getContainer()->get(EventStoreInterface::class);
        PreparedEventStoreSeeder::append($events, RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'test-model']]]));
        $replay = $this->createMock(\Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface::class);
        $replay->expects($this->once())->method('rebuildIfStale')->willReturn(\Ineersa\AgentCore\Application\Dto\RunStateReplayResult::noEvents());
        $middleware = new OwnerRunInitializationMiddleware($this->registry(), $events, $replay,
            self::getContainer()->get(HatfieldSessionStore::class),
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class),
            self::getContainer()->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
            new \Psr\Log\NullLogger(), self::getContainer()->get(AgentArtifactRegistry::class), self::getContainer()->get(PendingTransitionRecovery::class), self::getContainer()->get(\Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface::class), self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\AgentResumeCommandReader::class));
        try {
            $middleware->handle(new Envelope($this->start($run), [new ReceivedStamp('run_control')]), new StackMiddleware());
            $this->fail('Empty recovery must not admit queued state.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('produced no state', $exception->getMessage());
        }
        $this->expectException(RunContextNotLoadedException::class);
        $this->registry()->requireLoaded($run);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lateStartTerminalKinds')]
    public function testLateStartOnTerminalChildDoesNotReviveAndLeavesChildUnloaded(string $kind): void
    {
        $container = self::getContainer();
        $parent = $this->reserve();
        $identity = new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO(
            $parent,
            'child-terminal-'.$kind,
            'agent_terminal_'.$kind,
            'scout',
            'task',
            AgentArtifactKindEnum::Subagent,
        );
        $lifecycle = $container->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService::class);
        $lifecycle->reservePending($identity);

        $events = $container->get(EventStoreInterface::class);
        $registry = $this->registry();
        if ('completed' === $kind) {
            $input = new \Ineersa\AgentCore\Domain\Run\StartRunInput(
                'instructions',
                [],
                $identity->childRunId,
                new RunMetadata(session: ['kind' => 'agent_child', 'parent_run_id' => $parent], model: 'test-model'),
            );
            $container->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchRuntimeStartService::class)
                ->startPrepared($parent, 'launch-call-'.$kind, $identity, new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO($identity, $input));
            $queued = $container->get('messenger.transport.run_control')->getSent()[0];
            $this->assertInstanceOf(StartRun::class, $queued->getMessage());
            $container->get('agent.command.bus')->dispatch($queued->with(new ReceivedStamp('run_control')));
            $this->assertSame(RunStatus::Running, $registry->requireLoaded($identity->childRunId)->status);
            PreparedEventStoreSeeder::append(
                $events,
                RunEvent::forAppend($identity->childRunId, 0, 'agent_end', ['reason' => 'completed']),
            );
            $registry->release($identity->childRunId);
            $late = $queued;
        } else {
            $container->get('agent.command.bus')->dispatch(
                (new Envelope(new ApplyCommand($identity->childRunId, 0, 'cancel', 1, 'cancel-'.$kind, 'cancel')))
                    ->with(new ReceivedStamp('run_control')),
            );
            $projection = $container->get(RunOperationalProjectionRepository::class)->find($identity->childRunId);
            $this->assertNotNull($projection);
            $this->assertSame(RunStatus::Cancelled, $projection->status);
            $this->assertSame($parent, $projection->parentRunId);
            try {
                $registry->requireLoaded($identity->childRunId);
                $this->fail('Cancel-before-start must already release the finished child.');
            } catch (RunContextNotLoadedException $exception) {
                // Production cancel already released the finished child. Continue with late StartRun.
                $this->assertStringContainsString($identity->childRunId, $exception->getMessage());
            }
            $late = new Envelope($this->childStart($identity->childRunId, $parent), [new ReceivedStamp('run_control')]);
        }

        $beforeSeq = $events->latestSequenceFor($identity->childRunId);
        $this->assertNotNull($beforeSeq);
        $container->get('agent.command.bus')->dispatch($late);

        $this->assertSame($beforeSeq, $events->latestSequenceFor($identity->childRunId));
        $projection = $container->get(RunOperationalProjectionRepository::class)->find($identity->childRunId);
        $this->assertNotNull($projection);
        $this->assertSame($parent, $projection->parentRunId);
        $this->assertSame($parent, $projection->ownerSessionId);
        $this->assertTrue($container->get(RunRelationshipReaderInterface::class)->isAgentChild($identity->childRunId));
        $this->expectException(RunContextNotLoadedException::class);
        $registry->requireLoaded($identity->childRunId);
    }

    public static function lateStartTerminalKinds(): iterable
    {
        yield 'completed child' => ['completed'];
        yield 'cancel before start' => ['cancelled'];
    }

    public function testRecoveredParentConflictIsRejectedBeforePublication(): void
    {
        $container = self::getContainer();
        $parent = $this->reserve();
        $entry = $container->get(AgentArtifactRegistry::class)->create($parent, 'agent_conflict', 'child-conflict', 'scout', AgentArtifactKindEnum::Subagent);
        $this->registry()->createNew($entry->agentRunId);
        PreparedEventStoreSeeder::append(
            $container->get(EventStoreInterface::class),
            RunEvent::forAppend($entry->agentRunId, 0, 'run_started', ['payload' => ['metadata' => [
                'model' => 'test-model',
                'session' => ['kind' => 'agent_child', 'parent_run_id' => 'conflicting-parent'],
            ]]]),
        );
        $this->registry()->release($entry->agentRunId);

        try {
            $this->consume($this->childStart($entry->agentRunId, $parent));
            $this->fail('Conflicting recovered ownership must not be published.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('parent identity conflicts with child reservation', $exception->getMessage());
        }
        $projection = $container->get(RunOperationalProjectionRepository::class)->find($entry->agentRunId);
        $this->assertNotNull($projection);
        $this->assertSame($parent, $projection->parentRunId);
        $this->assertSame($parent, $projection->ownerSessionId);
        $this->assertSame(0, $projection->lastEventSequence);
        $this->expectException(RunContextNotLoadedException::class);
        $this->registry()->requireLoaded($entry->agentRunId);
    }

    private function reserve(): string
    {
        return self::getContainer()->get(HatfieldSessionStore::class)->createSession('owner boundary');
    }

    private function registry(): ActiveRunContextInterface
    {
        return self::getContainer()->get(ActiveRunContextInterface::class);
    }

    private function start(string $run): StartRun
    {
        return new StartRun($run, 0, 'start', 1, 'start', new StartRunPayload('system', metadata: new RunMetadata(model: 'test-model')));
    }

    private function childStart(string $run, string $parent): StartRun
    {
        return new StartRun(
            $run,
            0,
            'start',
            1,
            'start-late-'.$run,
            new StartRunPayload(
                'system',
                metadata: new RunMetadata(session: ['kind' => 'agent_child', 'parent_run_id' => $parent], model: 'test-model'),
            ),
        );
    }

    private function consume(object $message): void
    {
        self::getContainer()->get(OwnerRunInitializationMiddleware::class)->handle(new Envelope($message, [new ReceivedStamp('run_control')]), new StackMiddleware());
    }
}
