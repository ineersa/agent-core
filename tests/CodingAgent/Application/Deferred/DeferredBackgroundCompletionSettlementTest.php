<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Deferred;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\RedriveDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\ProcessedToolResultDTO;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeProcessedResultSubscriber;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Application\Deferred\DeferredCallerAcknowledgementService;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Application\Message\RepairSession;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class DeferredBackgroundCompletionSettlementTest extends PerMethodIsolatedKernelTestCase
{
    private InMemoryTransport $transport;
    private int $seen = 0;

    public static function repairCases(): iterable
    {
        foreach (['failed send', 'missing receipt', 'mailbox refusal'] as $failure) {
            yield $failure => [$failure, false, true];
            yield $failure.' with idle root' => [$failure, true, true];
            yield $failure.' with generic child' => [$failure, true, false];
        }
    }

    #[DataProvider('repairCases')]
    public function testExplicitRepairRetainsFirstTerminalUntilReceiptAdmission(string $failure, bool $idle, bool $native): void
    {
        [$fixture, $id] = $this->acknowledged($native);
        $c = self::getContainer();
        $run = $fixture->state->runId;
        $identity = ($native ? 'background-' : 'background-child-result-').$id;
        if ($idle) {
            $this->completeRoot($run);
        }
        $mailbox = $c->get(CommandStoreInterface::class);
        if ('mailbox refusal' === $failure) {
            for ($i = 0; $i < 100; ++$i) {
                $mailbox->enqueue(new PendingCommand($run, CoreCommandKind::AppendMessage, 'full-'.$i));
            }
        }
        $this->transport->failCompletion = 'failed send' === $failure;
        $this->publish($fixture, $id, $native);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $this->drain(notifications: 'missing receipt' !== $failure);
        $first = $deferred->findAcceptedResultReferenceByDeferredId($id);
        $this->assertNotNull($first);
        $this->assertSame('failed send' === $failure ? 1 : 0, $this->transport->failedSends);
        $this->assertSame('pending', $deferred->status($id));
        $this->assertNull($c->get(BackgroundCompletionReceiptRepository::class)->receipt($identity));
        $this->assertEquals($fixture->call, $c->get(ToolBatchStoreInterface::class)->loadCall($run, 1, 'step', 'cm'));

        // A competing terminal delivery cannot replace the first accepted body.
        $this->transport->failCompletion = false;
        $this->dispatch(new CompleteDeferredToolCall($id, [['type' => 'text', 'text' => 'late replacement']]));
        $this->drain(notifications: false);
        $this->assertEquals($first, $deferred->findAcceptedResultReferenceByDeferredId($id));

        $before = $this->transport->getSent();
        $preview = $c->get(SessionRepairService::class)->repair($run, false, 'preview');
        $this->assertNull($preview->refusalReason);
        $this->assertStringContainsString('accepted deferred', $preview->message);
        $this->assertEquals($before, $this->transport->getSent());
        $this->assertEquals($first, $deferred->findAcceptedResultReferenceByDeferredId($id));
        $this->transport->failCompletion = false;
        if ('mailbox refusal' === $failure) {
            foreach ($mailbox->pendingKeys($run) as $key) {
                $mailbox->markApplied($run, $key);
            }
        }
        $this->dispatch(new RepairSession($run, true, 'repair'));
        $this->drain();
        $receipt = $c->get(BackgroundCompletionReceiptRepository::class)->receipt($identity);
        $this->assertNotNull($receipt);
        $this->assertStringStartsWith('42', $receipt->text);
        $this->assertStringNotContainsString('late replacement', $receipt->text);
        $this->assertSame('completed', $deferred->status($id));
        $this->assertNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($run, 1, 'step'));
        $this->assertSame([$identity], $mailbox->pendingKeys($run));
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $this->dispatch(new DeliverBackgroundCompletionDTO($run, $identity, 'replacement must not win'));
        $this->drain();
        $this->assertEquals($receipt, $c->get(BackgroundCompletionReceiptRepository::class)->receipt($identity));
        $this->assertEquals($events, $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run));
        $this->assertSame([], $c->get('messenger.transport.tool')->getSent(), 'Repair must not re-execute source tools or processors.');
        $state = $c->get(ActiveRunContextInterface::class)->requireLoaded($run);
        $this->dispatch(new AdvanceRun($run, $state->turnNo, 'consume-notice', 1, 'consume-notice'));
        $this->drain(advances: true);
        $this->assertSame(BackgroundCompletionDispositionEnum::Consumed, $c->get(BackgroundCompletionReceiptRepository::class)->receipt($identity)->disposition);
        $this->assertSame([], $mailbox->pendingKeys($run));
        $consumedEvents = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $this->dispatch(new DeliverBackgroundCompletionDTO($run, $identity, 'replacement after consumption'));
        $this->drain();
        $this->assertEquals($consumedEvents, $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run));
    }

    public static function repairRefusals(): iterable
    {
        yield 'streaming' => [true];
        yield 'duplicate canonical sequence' => [false];
    }

    #[DataProvider('repairRefusals')]
    public function testRefusedRepairDoesNotRedeliverOrMutateAcceptedTerminal(bool $streaming): void
    {
        [$fixture, $id] = $this->acknowledged();
        $this->publish($fixture, $id);
        $this->drain(notifications: false);
        $c = self::getContainer();
        $run = $fixture->state->runId;
        $path = $c->get(RunHistorySourceProviderInterface::class)->historySource($run)->path;
        if ($streaming) {
            $context = $c->get(ActiveRunContextInterface::class);
            $context->replaceCurrent($context->requireLoaded($run)->with(['isStreaming' => true]));
        } else {
            $line = explode("\n", file_get_contents($path), 2)[0];
            (new Filesystem())->appendToFile($path, $line."\n");
        }
        $archive = file_get_contents($path);
        $sent = $this->transport->getSent();
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $first = $deferred->findAcceptedResultReferenceByDeferredId($id);
        $result = $c->get(SessionRepairService::class)->repair($run, true, 'refused');
        $this->assertSame($streaming ? SessionRepairRefusalReasonEnum::ActiveStreaming : SessionRepairRefusalReasonEnum::DuplicateSequences, $result->refusalReason);
        $this->assertEquals($sent, $this->transport->getSent());
        $this->assertSame($archive, file_get_contents($path));
        $this->assertEquals($first, $deferred->findAcceptedResultReferenceByDeferredId($id));
        $this->assertSame('pending', $deferred->status($id));
        $this->assertNull($c->get(BackgroundCompletionReceiptRepository::class)->receipt('background-'.$id));
    }

    public function testRollbackRetainsBodiesAndDuplicateAdmissionRetriesCleanup(): void
    {
        [$fixture, $id] = $this->acknowledged();
        $this->publish($fixture, $id);
        $this->drain(notifications: false);
        $c = self::getContainer();
        $db = $c->get(Connection::class);
        // Enqueue follows receipt and source-status metadata. Fail afterward to
        // prove that rollback cannot leave the immutable batch files deleted.
        $db->executeStatement("CREATE TRIGGER fail_receipt_settlement BEFORE INSERT ON run_command BEGIN SELECT RAISE(ABORT, 'receipt rollback'); END");
        try {
            $this->dispatch(new DeliverBackgroundCompletionDTO($fixture->state->runId, 'background-'.$id, '42'));
            try {
                $this->drain();
                $this->fail('Receipt metadata unexpectedly committed.');
            } catch (\Throwable $error) {
                $this->assertStringContainsString('receipt rollback', $error->getMessage());
            }
            $this->assertSame('pending', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
            $this->assertNull($c->get(BackgroundCompletionReceiptRepository::class)->receipt('background-'.$id));
            $this->assertEquals($fixture->call, $c->get(ToolBatchStoreInterface::class)->loadCall($fixture->state->runId, 1, 'step', 'cm'));
        } finally {
            $db->executeStatement('DROP TRIGGER fail_receipt_settlement');
        }
        // Messenger resets the closed manager before redelivery. This fixture
        // consumes envelopes directly, so perform that same reset explicitly.
        $c->get(ManagerRegistry::class)->resetManager();
        $db->executeStatement("CREATE TRIGGER retain_receipt_batch AFTER UPDATE OF status ON tool_deferred_completion WHEN NEW.status = 'completed' BEGIN UPDATE tool_batch SET finalized = 0 WHERE run_id = NEW.run_id; END");
        try {
            $this->dispatch(new DeliverBackgroundCompletionDTO($fixture->state->runId, 'background-'.$id, '42'));
            $this->drain();
            $this->assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
            $this->assertNotNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($fixture->state->runId, 1, 'step'));
        } finally {
            $db->executeStatement('DROP TRIGGER retain_receipt_batch');
        }
        $db->update('tool_batch', ['finalized' => 1], ['run_id' => $fixture->state->runId]);
        $this->dispatch(new DeliverBackgroundCompletionDTO($fixture->state->runId, 'background-'.$id, '42'));
        $this->drain();
        $this->assertNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($fixture->state->runId, 1, 'step'));
    }

    protected function afterKernelBoot(): void
    {
        $this->transport = new class extends InMemoryTransport {
            public bool $failCompletion = false;
            public int $failedSends = 0;

            public function send(Envelope $envelope): Envelope
            {
                if ($this->failCompletion && $envelope->getMessage() instanceof DeliverBackgroundCompletionDTO) {
                    ++$this->failedSends;
                    throw new TransportException('Fixture transport send failure.');
                }

                return parent::send($envelope);
            }
        };
        self::getContainer()->set('messenger.transport.run_control', $this->transport);
    }

    /** @return array{CodeModeBackendFixture, string} */
    private function acknowledged(bool $native = true): array
    {
        $c = self::getContainer();
        $fixture = new CodeModeBackendFixture($c, 'return 42;', $native ? 'code_mode' : 'subagent');
        $run = $fixture->state->runId;
        if (!$native) {
            $id = Uuid::v7()->toRfc4122();
            $call = $fixture->call;
            $c->get(DeferredToolCompletionRepositoryInterface::class)->registerPending(new DeferredToolCompletionCorrelation($id, $run, 1, 'step', 1, $call->idempotencyKey(), 'cm', $call->toolName, $call->args, 0, mode: 'sequential', timeoutSeconds: 8));
            $c->get(RunLockManager::class)->synchronized($run, static function () use ($c, $id, $fixture): void {
                $c->get(OwnerRunInitializationMiddleware::class)->initializeForOwner($fixture->state->runId, new RedriveDeferredToolCall($fixture->state->runId, $id));
                $result = $c->get(DeferredCallerAcknowledgementService::class)->acknowledge($id, $fixture->state, [['type' => 'text', 'text' => 'Child continues in background.']]);
                $c->get(RunCommit::class)->commit($fixture->state, $result->nextState, $result->events, postCommitActions: $result->postCommitActions, dispatchAfterTurnHooks: false);
            });

            return [$fixture, $id];
        }
        $service = $c->get(CodeModeExecutionService::class);
        $id = $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($run, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), $service->begin(...));
        $service->ready($id);
        $this->dispatch(new CodeModeOwnerDTO($run, $id, 'cm', true));
        $this->drain();
        $this->assertNotNull($c->get(DeferredToolCompletionRepositoryInterface::class)->acknowledgedAt($id));

        return [$fixture, $id];
    }

    private function publish(CodeModeBackendFixture $fixture, string $id, bool $native = true): void
    {
        if (!$native) {
            $this->dispatch(new CompleteDeferredToolCall($id, [['type' => 'text', 'text' => '42']]));

            return;
        }
        $c = self::getContainer();
        $c->get(CodeModeExecutionService::class)->preserve($id, 42, null);
        $result = new ToolCallResult($fixture->state->runId, 1, 'step', 1, $fixture->call->idempotencyKey(), 'cm', 0, ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => '42']]]);
        $event = new ProcessedToolResultDTO($result, $fixture->call);
        ($c->get(CodeModeProcessedResultSubscriber::class))($event);
        $this->assertTrue($event->handled);
    }

    private function completeRoot(string $run): void
    {
        $c = self::getContainer();
        $c->get(RunLockManager::class)->synchronized($run, static function () use ($c, $run): void {
            $state = $c->get(ActiveRunContextInterface::class)->requireLoaded($run);
            $event = (new EventFactory())->event($run, $state->lastSeq + 1, $state->turnNo, 'agent_end', ['reason' => 'completed']);
            $c->get(RunCommit::class)->commit($state, $state->with(['status' => RunStatus::Completed]), [$event], dispatchAfterTurnHooks: false);
        });
    }

    private function dispatch(object $message): void
    {
        self::getContainer()->get('agent.command.bus')->dispatch($message);
    }

    private function drain(bool $notifications = true, bool $advances = false): void
    {
        for ($round = 0; $round < 16; ++$round) {
            $sent = $this->transport->getSent();
            if ($this->seen >= \count($sent)) {
                return;
            }
            for (; $this->seen < \count($sent); ++$this->seen) {
                $envelope = $sent[$this->seen];
                $message = $envelope->getMessage();
                if ($message instanceof ToolPayloadMessage || $message instanceof CodeModeOwnerDTO || $message instanceof RepairSession || $message instanceof RedriveDeferredToolCall || ($notifications && $message instanceof DeliverBackgroundCompletionDTO) || ($advances && $message instanceof AdvanceRun)) {
                    self::getContainer()->get('agent.command.bus')->dispatch($envelope->with(new ReceivedStamp('run_control')));
                }
            }
        }
        $this->fail('Run-control fixture did not drain within its bounded message budget.');
    }
}
