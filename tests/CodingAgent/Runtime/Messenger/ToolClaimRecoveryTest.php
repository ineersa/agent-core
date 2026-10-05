<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolExecutionAuthorization;
use Ineersa\AgentCore\Application\Handler\ToolExecutionUnknownCoordinationHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionOutcomeUnknownHandler;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolExecutionOutcomeUnknown;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class ToolClaimRecoveryTest extends PerMethodIsolatedKernelTestCase
{
    public function testOrdinaryClaimChecksOtherAuthorityUnderOwnerSerialization(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('serialized tool claim');
        $call = new ExecuteToolCall($run, 1, 'tools', 1, 'invocation', 'read-1', 'read', ['path' => 'fixture'], 0);
        $store = $container->get(ToolBatchStoreInterface::class);
        (new ToolBatchCollector(store: $store))->registerExpectedBatch($run, 1, 'tools', [$call]);
        $locks = $container->get('hatfield.controller.session_owner.lock_factory');
        $ownerLocks = $container->get(\Symfony\Component\Lock\LockFactory::class);
        $otherAuthority = $this->createMock(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class);
        $otherAuthority->expects($this->once())->method('assertNoUnknownExecution')->with($run)->willReturnCallback(static function () use ($ownerLocks, $run): void {
            $probe = $ownerLocks->createLock('agent_loop.run.'.$run, ttl: null);
            self::assertFalse($probe->acquire(), 'The cross-authority check and claim must share owner serialization.');
        });
        $gate = new ToolExecutionAuthorization($store, $container->get(\Symfony\Component\Serializer\SerializerInterface::class), $container->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class), $locks, $otherAuthority, $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class));
        $gate->arm($call);
        $this->assertIsString($gate->claim($call));
    }

    public function testGenericClaimHoldsOwnerSerializationThroughAtomicUpdate(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('serialized generic claim');
        $request = new \Ineersa\AgentCore\Domain\Message\ExecuteLlmStep($run, 1, 'claim', 1, 'original', 'tools');
        $transitions = $container->get(PreparedTransitionEventStoreInterface::class);
        $transitions->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $verified = $transitions->verifiedPendingTransition($run);
        $this->assertNotNull($verified);
        $operations = $container->get(\Ineersa\CodingAgent\Session\DoctrineExecutionOperationStore::class);
        $stamp = $operations->arm($request, $verified);
        $reference = $operations->requestReference($request, $stamp);
        $transitions->finalizeVerifiedTransition($run, $verified->identity);
        $connection = $container->get('doctrine')->getConnection();
        $native = $connection->getNativeConnection();
        $this->assertInstanceOf(\Pdo\Sqlite::class, $native);
        $locks = $container->get(\Symfony\Component\Lock\LockFactory::class);
        $checks = 0;
        $native->createFunction('verify_owner_exclusion', static function () use ($locks, $run, &$checks): int {
            ++$checks;
            $probe = $locks->createLock('agent_loop.run.'.$run, ttl: null);
            self::assertFalse($probe->acquire(), 'The SQL claim must not race a batch-backed unknown decision.');

            return 1;
        }, 0);
        $connection->executeStatement("CREATE TEMP TRIGGER verify_claim_exclusion BEFORE UPDATE OF state ON execution_operation WHEN NEW.state = 'Running' BEGIN SELECT verify_owner_exclusion(); END");
        try {
            $this->assertIsString($operations->claim($reference, $stamp));
            $this->assertSame(1, $checks);
        } finally {
            $connection->executeStatement('DROP TRIGGER verify_claim_exclusion');
            $native->createFunction('verify_owner_exclusion', static fn (): int => 1, 0);
        }
    }

    public static function outcomes(): iterable
    {
        yield 'missing result' => ['missing'];
        yield 'published result' => ['ready'];
        yield 'published human suspension' => ['suspension'];
        yield 'complete orphan result' => ['orphan'];
        yield 'mismatching orphan result' => ['corrupt'];
        yield 'conflicting orphan results' => ['conflicting'];
        yield 'superseded invocation' => ['superseded'];
        yield 'pending deferred handoff' => ['deferred'];
        yield 'completed deferred handoff' => ['completed'];
    }

    #[DataProvider('outcomes')]
    public function testOwnedWorkerExclusionAndDeadClaimRecovery(string $mode): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('ordinary claim recovery');
        $call = new ExecuteToolCall($run, 1, 'tools', 1, 'invocation', 'read-1', 'read', ['path' => 'fixture'], 0);
        $store = $container->get(ToolBatchStoreInterface::class);
        (new ToolBatchCollector(store: $store))->registerExpectedBatch($run, 1, 'tools', [$call]);
        $gate = $container->get(ToolExecutionAuthorization::class);
        $gate->arm($call);
        $genericReference = $genericStamp = null;
        if ('missing' === $mode) {
            $request = new \Ineersa\AgentCore\Domain\Message\ExecuteLlmStep($run, 1, 'already-armed', 1, 'original-generic', 'tools');
            $transitions = $container->get(PreparedTransitionEventStoreInterface::class);
            $transitions->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
            $verified = $transitions->verifiedPendingTransition($run);
            $this->assertNotNull($verified);
            $operations = $container->get(\Ineersa\CodingAgent\Session\DoctrineExecutionOperationStore::class);
            $genericStamp = $operations->arm($request, $verified);
            $genericReference = $operations->requestReference($request, $genericStamp);
            $transitions->finalizeVerifiedTransition($run, $verified->identity);
        }
        $input = new InputStream();
        $process = new Process([\PHP_BINARY, __DIR__.'/Support/ToolClaimWorker.php', getcwd(), $run, $mode], env: ['HATFIELD_SESSION_ID' => false]);
        $process->setInput($input);
        $process->setTimeout(5);
        try {
            $process->start();
            $ready = '';
            $this->assertTrue($process->waitUntil(static function (string $type, string $output) use (&$ready): bool {
                if (Process::OUT === $type) {
                    $ready .= $output;
                }

                return str_contains($ready, "claim_ready\n");
            }), $process->getErrorOutput());
            $this->assertTrue($process->isRunning(), 'The ownership barrier must belong to a live worker.');
            $gate->recoverRunning($run, 1, 'tools');
            $batch = $store->load($run, 1, 'tools');
            $this->assertNotNull($batch);
            $receipt = array_values($batch->executionAuthorizations)[0];
            $this->assertSame(\in_array($mode, ['ready', 'suspension'], true) ? 'ResultReady' : 'Running', $receipt['state']);
            $claim = $receipt['claim'];
            if (\in_array($mode, ['deferred', 'completed'], true)) {
                $repository = $container->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class);
                $repository->registerPending(new \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation('known-deferred', $run, 1, 'tools', 1, 'invocation', 'read-1', 'read', ['path' => 'fixture'], 0));
                if ('completed' === $mode) {
                    $repository->markCompleted('known-deferred');
                }
            }
            if ('superseded' === $mode) {
                $batch->calls['read-1'] = new ExecuteToolCall($run, 1, 'tools', 2, 'new-invocation', 'read-1', 'read', ['path' => 'different'], 0);
                $store->save($run, 1, 'tools', $batch);
            }
            $input->write("finish\n");
            $input->close();
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            if (\in_array($mode, ['corrupt', 'conflicting'], true)) {
                try {
                    $gate->recoverRunning($run, 1, 'tools');
                    $this->fail('Mismatching result evidence must not be adopted or discarded.');
                } catch (\RuntimeException $exception) {
                    $this->assertStringContainsString('corrupt' === $mode ? 'differs from its claimed invocation' : 'Conflicting orphan tool results', $exception->getMessage());
                }
                $batch = $store->load($run, 1, 'tools');
                $this->assertNotNull($batch);
                $this->assertSame('Running', array_values($batch->executionAuthorizations)[0]['state']);
                $path = $container->get(\Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface::class)->resolveToolBatchesDirectory($run).'/1_'.hash('sha256', 'tools').'.json.tmp.complete';
                $this->assertFileExists($path);
                if ('conflicting' === $mode) {
                    $this->assertFileExists($path.'.second');
                }

                return;
            }
            $gate->recoverRunning($run, 1, 'tools');
            $batch = $store->load($run, 1, 'tools');
            $this->assertNotNull($batch);
            $this->assertSame($claim, array_values($batch->executionAuthorizations)[0]['claim']);
            $deliveries = iterator_to_array($gate->pendingDeliveries($run, 1, 'tools', $batch), false);
            if (\in_array($mode, ['deferred', 'completed'], true)) {
                $this->assertSame('Deferred', array_values($batch->executionAuthorizations)[0]['state']);
                $this->assertSame('known-deferred', array_values($batch->executionAuthorizations)[0]['deferred_id']);
                $this->assertSame([], $deliveries);
                $this->assertFalse($store->hasOutcomeUnknown($run));

                return;
            }
            $this->assertCount(1, $deliveries);
            if (\in_array($mode, ['ready', 'orphan', 'suspension'], true)) {
                $this->assertInstanceOf(ToolCallResult::class, $deliveries[0]);
                if ('suspension' === $mode) {
                    $this->assertTrue($deliveries[0]->isHumanInputSuspension());
                    $this->assertSame('question-1', $deliveries[0]->pendingHumanInput->questionId);
                    $state = new RunState(runId: $run, status: RunStatus::Running, turnNo: 1, activeStepId: 'tools', pendingToolCalls: ['read-1' => false]);
                    $decision = $container->get(\Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler::class)->handle($deliveries[0], $state);
                    $this->assertSame(RunStatus::WaitingHuman, $decision->nextState->status);
                    $this->assertSame('question-1', $decision->nextState->pendingHumanInputRequests[0]->questionId);
                } else {
                    $this->assertSame('original durable tool result', $deliveries[0]->result);
                }
                $this->assertEquals($deliveries[0], $gate->claim($call), 'Redelivery must reuse the original persisted outcome.');
                $this->assertSame('ResultReady', array_values($batch->executionAuthorizations)[0]['state']);

                return;
            }
            $notice = $deliveries[0];
            $this->assertInstanceOf(ToolExecutionOutcomeUnknown::class, $notice);
            $this->assertSame('OutcomeUnknown', array_values($batch->executionAuthorizations)[0]['state']);
            $this->assertNull($gate->claim($call), 'An ambiguous arbitrary tool must not execute again.');
            $this->assertEquals($deliveries, iterator_to_array($gate->pendingDeliveries($run, 1, 'tools', $batch), false), 'A lost unknown notification must remain discoverable.');
            $state = new RunState(runId: $run, status: RunStatus::Running, turnNo: 1, activeStepId: 'tools', pendingToolCalls: ['read-1' => false]);
            $decision = $container->get(ToolExecutionOutcomeUnknownHandler::class)->handle($notice, $state);
            if ('superseded' === $mode) {
                $this->assertSame(RunStatus::Running, $decision->nextState->status);
                $this->assertSame([], $decision->events, 'An old receipt must not fail a revised invocation.');
            } else {
                $this->assertSame(RunStatus::Failed, $decision->nextState->status);
                $this->assertStringContainsString('Side effects may already have occurred', $decision->nextState->errorMessage);
            }
            $events = $container->get(PreparedTransitionEventStoreInterface::class);
            $events->appendTransition($decision->events, ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [], 'actions' => $decision->postCommitActions]);
            $pending = $events->verifiedPendingTransition($run);
            $this->assertNotNull($pending);
            ($container->get(ToolExecutionUnknownCoordinationHandler::class))($decision->postCommitActions[0]);
            // Recover process death after durable acknowledgement but before
            // finalization. The verified action must remain idempotent.
            $container->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class)->recover($run);
            $this->assertNull($events->verifiedPendingTransition($run));
            $this->assertFalse($gate->unknownNoticePending($notice));
            $this->assertSame([], iterator_to_array($gate->pendingDeliveries($run, 1, 'tools', $store->load($run, 1, 'tools')), false));
            $this->assertTrue($store->hasOutcomeUnknown($run), 'Notice acknowledgement must not authorize another attempt.');
            if ('missing' === $mode) {
                $this->assertNotNull($genericReference);
                $this->assertNotNull($genericStamp);
                $stack = $this->createMock(\Symfony\Component\Messenger\Middleware\StackInterface::class);
                $stack->expects($this->never())->method('next');
                try {
                    $container->get(\Ineersa\AgentCore\Application\Messenger\ExecutionAuthorizationMiddleware::class)->handle(new \Symfony\Component\Messenger\Envelope($genericReference, [$genericStamp, new \Symfony\Component\Messenger\Stamp\ReceivedStamp('llm')]), $stack);
                    $this->fail('An ordinary-tool unknown must also block an armed generic execution.');
                } catch (\RuntimeException $exception) {
                    $this->assertStringContainsString('Execution outcome is unknown', $exception->getMessage());
                }
            }
            $other = new ExecuteToolCall($run, 2, 'later-tools', 1, 'later-invocation', 'read-2', 'read', ['path' => 'later'], 0);
            (new ToolBatchCollector(store: $store))->registerExpectedBatch($run, 2, 'later-tools', [$other]);
            $gate->arm($other);
            try {
                $gate->claim($other);
                $this->fail('An unresolved unknown must block another batch claim.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Execution outcome is unknown', $exception->getMessage());
            }
        } finally {
            if ($process->isRunning()) {
                $input->write("finish\n");
                $input->close();
                $process->wait();
            }
        }
    }
}
