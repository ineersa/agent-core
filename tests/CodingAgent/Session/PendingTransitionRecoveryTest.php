<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\CommandMailboxCoordinationFactory;
use Ineersa\AgentCore\Application\Handler\CoordinationActionValidator;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Exception\RunContextNotLoadedException;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\MessageBusInterface;

final class PendingTransitionRecoveryTest extends IsolatedKernelTestCase
{
    public function testMailboxPreparationAndRecoveryPreserveOnePendingCommand(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('mailbox recovery');
        $commands = $container->get(CommandStoreInterface::class);
        $handler = $container->get(ApplyCommandHandler::class);
        $message = new ApplyCommand($run, 1, 'input', 1, 'original-mailbox-command', 'follow_up', ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'original input']]]]);
        $state = new RunState(runId: $run, status: RunStatus::Running, turnNo: 1);
        $result = $handler->handle($message, $state);
        $this->assertFalse($commands->has($run, $message->idempotencyKey()), 'Failure before intent must not reserve or consume the source command.');
        $this->assertSame([], $commands->pending($run));
        $retry = $handler->handle($message, $state);
        $this->assertEquals($result->postCommitActions, $retry->postCommitActions);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition($result->events, ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => $result->postCommitActions]);
        $this->assertFalse($commands->has($run, $message->idempotencyKey()));
        $bus = $container->get('agent.command.bus');
        $this->recovery($bus)->recover($run);
        $this->assertNull($store->verifiedPendingTransition($run));
        $pending = $commands->pending($run);
        $this->assertCount(1, $pending);
        $this->assertSame($message->idempotencyKey(), $pending[0]->idempotencyKey);
        $this->assertSame($message->payload, $pending[0]->payload);
        $this->assertSame(1, $store->latestSequenceFor($run));
        $this->recovery($bus)->recover($run);
        $this->assertEquals($pending, $commands->pending($run));
    }

    public function testUnsupportedActionIsRejectedBeforeAnyMailboxFinalization(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('unsupported coordination');
        $commands = $container->get(CommandStoreInterface::class);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [new MarkCommandAppliedDTO($run, 'must-not-apply'), new \stdClass()]]);
        try {
            $this->recovery($container->get('agent.command.bus'))->recover($run);
            $this->fail('Unsupported work must not finalize.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Owner transition requires coordination recovery for unsupported action.', $exception->getMessage());
        }
        $this->assertFalse($commands->has($run, 'must-not-apply'));
        $this->assertNotNull($store->verifiedPendingTransition($run));
    }

    public function testRecoveryReleasesWarmRegistryAndSendsAfterOwnerLockRelease(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('warm registry invalidation');
        $request = new ExecuteLlmStep($run, 1, 'execution', 1, 'original-request', 'tools');
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $registry = $container->get(ActiveRunContextInterface::class);
        $registry->loadRecovered(new RunState(runId: $run, status: RunStatus::Running, turnNo: 1));
        $this->assertSame($run, $registry->requireLoaded($run)->runId);
        // Finalization and registry release happen under the owner lock.
        // Broker publication is scheduled after release and cannot keep the
        // warm predecessor alive.
        $bus = new TestMessageBus();
        $locks = new RunLockManager(new LockFactory(new InMemoryStore()));
        $locks->synchronized($run, function () use ($bus, $locks, $run): void {
            $this->recovery($bus, $locks)->recover($run);
            $this->assertSame([], $bus->messages, 'Recovery must not send while the owner lock is held.');
        });
        $this->assertEquals([$request], $bus->messages);
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->expectException(RunContextNotLoadedException::class);
        $registry->requireLoaded($run);
    }

    public static function mailboxDecisions(): iterable
    {
        yield 'applied' => [false];
        yield 'rejected' => [true];
    }

    #[DataProvider('mailboxDecisions')]
    public function testInterruptedMailboxDrainRetainsFifoCutoffAndFinishesDecisions(bool $rejected): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('mailbox drain recovery');
        $commands = $container->get(CommandStoreInterface::class);
        $payload = $rejected ? [] : ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'queued input']]]];
        $commands->enqueue(new PendingCommand($run, 'steer', 'first', $payload));
        $commands->enqueue(new PendingCommand($run, 'steer', 'second', $payload));
        $state = new RunState(runId: $run, status: RunStatus::Running, turnNo: 1);
        $prepared = $container->get(CommandMailboxPolicy::class)->applyPendingTurnStartCommands($state);
        $this->assertSame(['first', 'second'], array_column($commands->pending($run), 'idempotencyKey'));
        $events = $container->get(EventFactory::class)->eventsFromSpecs($run, 1, 1, $prepared->eventSpecs);
        $decision = CommandMailboxCoordinationFactory::finalize(new HandlerResult(events: $events));
        $this->assertSame(['first', 'second'], array_map(static fn ($event): string => $event->payload['idempotency_key'], $events));
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition($events, ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => $decision->postCommitActions]);
        $this->assertCount(2, $commands->pending($run));
        // This later enqueue is outside the captured cutoff and must survive recovery.
        $commands->enqueue(new PendingCommand($run, 'steer', 'later', $payload));
        $bus = $container->get('agent.command.bus');
        $this->recovery($bus)->recover($run);
        $this->assertSame(['later'], array_column($commands->pending($run), 'idempotencyKey'));
        $this->assertFalse($commands->has($run, 'first'));
        $this->assertFalse($commands->has($run, 'second'));
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertSame(2, $store->latestSequenceFor($run));
        $this->recovery($bus)->recover($run);
        $this->assertSame(['later'], array_column($commands->pending($run), 'idempotencyKey'));
    }

    private function recovery(MessageBusInterface $bus, ?RunLockManager $locks = null): PendingTransitionRecovery
    {
        $container = self::getContainer();

        $store = $container->get(PreparedTransitionEventStoreInterface::class);

        return new PendingTransitionRecovery(
            $store,
            $container->get(ActiveRunContextInterface::class),
            TestTransitionFinalizerFactory::create($store, new StepDispatcher($bus, $bus, new TestLogger(), events: new EventDispatcher()), batches: $container->get(ToolBatchStoreInterface::class), commands: $container->get(CommandStoreInterface::class), locks: $locks),
            $container->get(CoordinationActionValidator::class),
        );
    }
}
