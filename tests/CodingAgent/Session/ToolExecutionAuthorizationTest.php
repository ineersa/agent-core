<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolExecutionAuthorization;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

final class ToolExecutionAuthorizationTest extends PerMethodIsolatedKernelTestCase
{
    public function testClaimRetainsNonExpiringWorkerExclusion(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('tool worker exclusion');
        $call = $this->call($run);
        $this->prepare($call);
        $gate = $this->gate();
        $this->assertIsString($gate->claim($call));
        $batch = $container->get(ToolBatchStoreInterface::class)->load($run, 1, 'tools');
        $this->assertNotNull($batch);
        $authorization = array_values($batch->executionAuthorizations)[0];
        $lock = $container->get('hatfield.controller.session_owner.lock_factory')->createLock('tool-execution-worker.'.$authorization['claim_lock_key'], ttl: null);
        try {
            $this->assertFalse($lock->acquire(), 'A retained worker must exclude recovery.');
            unset($gate);
            $this->assertTrue($lock->acquire(), 'Worker lifetime completion releases exclusion.');
        } finally {
            $lock->release();
        }
    }

    public function testRunningClaimCannotBeTakenByAnotherWorkerInstance(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('tool gate');
        $call = $this->call($run);
        $this->prepare($call);
        $this->assertIsString($this->gate()->claim($call));
        $this->assertNull($this->gate()->claim($call));
    }

    public function testOwnerConsumesDurableResultOnceWithoutExtraAdvance(): void
    {
        $this->exerciseOwnerDisposition(false);
    }

    public function testEventFreeDelayedResultGetsStaleDisposition(): void
    {
        $this->exerciseOwnerDisposition(true);
    }

    public function testConfiguredOwnerCommitArmsOnlyAfterCanonicalAppend(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('owner arms tool');
        $call = $this->call($run);
        (new ToolBatchCollector(store: $container->get(ToolBatchStoreInterface::class)))->registerExpectedBatch($run, 1, 'tools', [$call]);
        $state = \Ineersa\AgentCore\Domain\Run\RunState::queued($run);
        $container->get(\Ineersa\AgentCore\Contract\ActiveRunContextInterface::class)->loadRecovered($state);
        try {
            $this->gate()->claim($call);
            $this->fail('Preparing a batch must not authorize execution.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Tool execution has no owner authorization.', $exception->getMessage());
        }
        $container->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class)->commit(
            $state, $state,
            [\Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'tool_execution_start', ['tool_call_id' => 'read-1'])],
            [$call], dispatchAfterTurnHooks: false,
        );
        $this->assertIsString($this->gate()->claim($call));
        $this->assertSame(1, $container->get(\Ineersa\AgentCore\Contract\EventStoreInterface::class)->latestSequenceFor($run));
    }

    public function testChildClaimAndResultUseParentScopedBatchStorage(): void
    {
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $parent = $sessions->createSession('child tool gate');
        $child = '123e4567-e89b-12d3-a456-426614174000';
        $artifact = 'agent_gate';
        self::getContainer()->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class)->register(new \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactEntryDTO(
            artifactId: $artifact, parentRunId: $parent, agentRunId: $child, agentName: 'scout',
            kind: \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum::Subagent,
            status: \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum::Running,
            paths: \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathsDTO::forArtifactId($artifact), createdAt: new \DateTimeImmutable(),
        ));
        $call = $this->call($child);
        $this->prepare($call);
        $executor = $this->createMock(ToolExecutorInterface::class);
        $executor->expects($this->once())->method('execute')->willReturn(new ToolResult('read-1', 'read', [['type' => 'text', 'text' => 'child result']]));
        $bus = new TestMessageBus();
        ($this->worker($executor, $bus, $this->gate()))($call);
        $this->assertInstanceOf(ToolCallResult::class, $this->gate()->claim($call));
        $this->assertDirectoryExists($sessions->resolveSessionsBasePath().'/'.$parent.'/artifacts/agents/'.$artifact.'/runtime/tool-batches');
        $this->assertDirectoryDoesNotExist($sessions->resolveSessionsBasePath().'/'.$child);
    }

    public function testMissingAuthorizationRejectsDeliveryWithoutExternalExecution(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('missing gate');
        $executor = $this->createMock(ToolExecutorInterface::class);
        $executor->expects($this->never())->method('execute');
        $worker = $this->worker($executor, new TestMessageBus(), $this->gate());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing or differs');
        $worker($this->call($run));
    }

    public function testCanonicalCancellationRetainsMixedBatchAndAcceptsDelayedDurableResult(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('cancel retains claims');
        $first = $this->call($run);
        $second = new ExecuteToolCall(runId: $run, turnNo: 1, stepId: 'tools', attempt: 1, idempotencyKey: 'second', toolCallId: 'read-2', toolName: 'read', args: [], orderIndex: 1);
        $store = $container->get(ToolBatchStoreInterface::class);
        (new ToolBatchCollector(store: $store))->registerExpectedBatch($run, 1, 'tools', [$first, $second]);
        $gate = $this->gate();
        $gate->arm($first);
        $gate->arm($second);
        $firstClaim = $gate->claim($first);
        $secondClaim = $gate->claim($second);
        $this->assertIsString($firstClaim);
        $this->assertIsString($secondClaim);
        $secondResult = \Ineersa\AgentCore\Application\Handler\ToolCallResultFactory::fromExecuteToolCallAndToolResult($second, new ToolResult('read-2', 'read', [['type' => 'text', 'text' => 'finished sibling']]));
        $gate->saveResult($second, $secondClaim, $secondResult);
        $state = \Ineersa\AgentCore\Domain\Run\RunState::queued($run);
        $container->get(\Ineersa\AgentCore\Contract\ActiveRunContextInterface::class)->loadRecovered($state);
        $container->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class)->commit($state, $state->with(['status' => \Ineersa\AgentCore\Domain\Run\RunStatus::Cancelled]), [\Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($run, 1, 'agent_end', ['reason' => 'cancelled'])]);
        $this->assertNotNull($store->load($run, 1, 'tools'));
        $this->assertNull($this->gate()->claim($first), 'Cancellation does not authorize another external execution.');
        $firstResult = \Ineersa\AgentCore\Application\Handler\ToolCallResultFactory::fromExecuteToolCallAndToolResult($first, new ToolResult('read-1', 'read', [['type' => 'text', 'text' => 'delayed external outcome']]));
        $this->gate()->saveResult($first, $firstClaim, $firstResult);
        $store->delete($run, 1, 'tools');
        $store->deleteAllForRun($run);
        $this->assertEquals($firstResult, $this->gate()->claim($first));
        $this->assertEquals($secondResult, $this->gate()->claim($second));
    }

    public function testLostNotificationRedeliveryUsesExactDurableResultWithoutReexecution(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('durable tool result');
        $call = $this->call($run);
        $this->prepare($call);
        $executor = $this->createMock(ToolExecutorInterface::class);
        $executor->expects($this->once())->method('execute')->willReturn(new ToolResult(toolCallId: 'read-1', toolName: 'read', content: [['type' => 'text', 'text' => 'durable output']]));
        $failedBus = $this->createMock(MessageBusInterface::class);
        $captured = null;
        $failedBus->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$captured): Envelope {
            $captured = $message;
            throw new \RuntimeException('notification lost');
        });
        try {
            ($this->worker($executor, $failedBus, $this->gate()))($call);
            $this->fail('Notification failure must leave the execution unacknowledged.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('notification lost', $exception->getMessage());
        }
        $this->assertInstanceOf(ToolCallResult::class, $captured);
        $freshExecutor = $this->createMock(ToolExecutorInterface::class);
        $freshExecutor->expects($this->never())->method('execute');
        $bus = new TestMessageBus();
        ($this->worker($freshExecutor, $bus, $this->gate()))($call);
        $this->assertCount(1, $bus->messages);
        $this->assertEquals($captured, $bus->messages[0]);
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function dispositionCrashBoundaries(): iterable
    {
        yield 'intent before physical append' => [true, false, false];
        yield 'complete append before disposition' => [false, false, false];
        yield 'disposition before finalization' => [false, true, false];
        yield 'event-free stale decision' => [false, false, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dispositionCrashBoundaries')]
    public function testOwnerRecoversJournalBoundDispositionAndAcceptsFutureCommit(bool $truncate, bool $disposed, bool $stale): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('recover result disposition');
        $events = $container->get(\Ineersa\CodingAgent\Session\SessionRunEventStore::class);
        $events->append(new \Ineersa\AgentCore\Domain\Event\RunEvent($run, 0, 0, 'run_started', []));
        $call = $this->call($run);
        $this->prepare($call);
        $gate = $this->gate();
        $claim = $gate->claim($call);
        $this->assertIsString($claim);
        $result = \Ineersa\AgentCore\Application\Handler\ToolCallResultFactory::fromExecuteToolCallAndToolResult($call, new ToolResult('read-1', 'read', [['type' => 'text', 'text' => 'original outcome']]));
        $gate->saveResult($call, $claim, $result);
        $descriptor = $gate->prepareDisposition($result, $stale ? 'Stale' : 'Consumed');
        $this->assertNotNull($descriptor);
        $planned = $stale ? [] : [new \Ineersa\AgentCore\Domain\Event\RunEvent($run, 0, 1, 'agent_end', ['reason' => 'completed'])];
        $events->appendTransition($planned, ['run_id' => $run, 'predecessor_seq' => 1, 'source' => ['result_hash' => $descriptor->resultHash], 'result_disposition' => $descriptor]);
        $verified = $events->verifiedPendingTransition($run);
        $this->assertNotNull($verified);
        $path = $sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl';
        if ($truncate) {
            $handle = fopen($path, 'r+b');
            try {
                $this->assertTrue(ftruncate($handle, $verified->startOffset));
            } finally {
                fclose($handle);
            }
        }
        if ($disposed) {
            $gate->applyDisposition($descriptor, $verified);
        }
        unset($gate, $verified);
        $process = new \Symfony\Component\Process\Process([\PHP_BINARY, __DIR__.'/Support/RecoverPendingTransition.php', getcwd(), $run], env: ['HATFIELD_SESSION_ID' => false]);
        $process->setTimeout(5);
        $process->mustRun();
        $this->assertStringContainsString("recovered\n", $process->getOutput());
        // Discard the complete owner service graph. Durable files, not a retained
        // pending descriptor or RunState, must drive the next owner admission.
        self::$kernel->shutdown();
        self::$kernel = null;
        self::$booted = false;
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $container = self::getContainer();
        $events = $container->get(\Ineersa\CodingAgent\Session\SessionRunEventStore::class);
        $active = $container->get(\Ineersa\AgentCore\Contract\ActiveRunContextInterface::class);
        $active->release($run);
        $container->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class)->initializeForOwner($run, new \Ineersa\AgentCore\Domain\Message\AdvanceRun($run, 0, 'recovery', 1, 'recovery'));
        $this->assertFileDoesNotExist($path.'.append.pending.json');
        $batch = $container->get(ToolBatchStoreInterface::class)->load($run, 1, 'tools');
        $this->assertSame($stale ? 'Stale' : 'Consumed', array_values($batch->executionAuthorizations)[0]['state']);
        $this->assertSame([], $batch->pendingDispositions);
        $this->assertTrue($this->gate()->isDisposed($result));
        $this->assertNull($this->gate()->claim($call));
        $state = $active->requireLoaded($run);
        $this->assertSame($stale ? 1 : 2, $state->lastSeq);
        $container->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class)->commit($state, $state, [new \Ineersa\AgentCore\Domain\Event\RunEvent($run, 0, 0, 'agent_end', ['reason' => 'completed'])], dispatchAfterTurnHooks: false);
        $this->assertSame($stale ? 2 : 3, $events->latestSequenceFor($run));
    }

    /** @return iterable<string, array{bool}> */
    public static function invalidRecoveryResults(): iterable
    {
        yield 'missing result' => [false];
        yield 'mismatched result' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRecoveryResults')]
    public function testRecoveryRejectsMissingDurableResultAndRetainsUnpublishedCut(bool $corrupt): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('missing result recovery');
        $events = $container->get(\Ineersa\CodingAgent\Session\SessionRunEventStore::class);
        $events->append(new \Ineersa\AgentCore\Domain\Event\RunEvent($run, 0, 0, 'run_started', []));
        $call = $this->call($run);
        $this->prepare($call);
        $gate = $this->gate();
        $claim = $gate->claim($call);
        $this->assertIsString($claim);
        $result = \Ineersa\AgentCore\Application\Handler\ToolCallResultFactory::fromExecuteToolCallAndToolResult($call, new ToolResult('read-1', 'read', [['type' => 'text', 'text' => 'original outcome']]));
        $gate->saveResult($call, $claim, $result);
        $descriptor = $gate->prepareDisposition($result, 'Consumed');
        $this->assertNotNull($descriptor);
        $events->appendTransition([new \Ineersa\AgentCore\Domain\Event\RunEvent($run, 0, 1, 'agent_end', ['reason' => 'completed'])], ['run_id' => $run, 'predecessor_seq' => 1, 'result_disposition' => $descriptor]);
        $store = $container->get(ToolBatchStoreInterface::class);
        $replacement = \Ineersa\AgentCore\Application\Handler\ToolCallResultFactory::fromExecuteToolCallAndToolResult($call, new ToolResult('read-1', 'read', [['type' => 'text', 'text' => 'different outcome']]));
        $store->mutate($run, 1, 'tools', static function ($batch) use ($corrupt, $replacement): \Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation {
            $batch->executionResults = $corrupt ? [array_key_first($batch->executionResults) => $replacement] : [];

            return new \Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation(null, $batch);
        });
        try {
            $container->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class)->initializeForOwner($run, new \Ineersa\AgentCore\Domain\Message\AdvanceRun($run, 0, 'recovery', 1, 'recovery'));
            $this->fail('Missing result must not be consumed or fabricated.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('evidence is missing', $exception->getMessage());
        }
        $this->assertFileExists($sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl.append.pending.json');
        $this->assertSame(1, $events->latestSequenceFor($run));
        $this->assertSame('ResultReady', array_values($store->load($run, 1, 'tools')->executionAuthorizations)[0]['state']);
    }

    private function exerciseOwnerDisposition(bool $stale): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('result disposition');
        $call = $this->call($run);
        $this->prepare($call);
        $gate = $this->gate();
        $claim = $gate->claim($call);
        $this->assertIsString($claim);
        $result = \Ineersa\AgentCore\Application\Handler\ToolCallResultFactory::fromExecuteToolCallAndToolResult($call, new ToolResult('read-1', 'read', [['type' => 'text', 'text' => 'exact durable result']]));
        $gate->saveResult($call, $claim, $result);
        $state = new \Ineersa\AgentCore\Domain\Run\RunState(runId: $run, status: $stale ? \Ineersa\AgentCore\Domain\Run\RunStatus::Cancelled : \Ineersa\AgentCore\Domain\Run\RunStatus::Running, turnNo: $stale ? 2 : 1, activeStepId: $stale ? null : 'tools', pendingToolCalls: ['read-1' => false]);
        $active = $container->get(\Ineersa\AgentCore\Contract\ActiveRunContextInterface::class);
        $active->loadRecovered($state);
        $bus = new TestMessageBus();
        $dispatcher = new \Ineersa\AgentCore\Application\Handler\StepDispatcher($bus, $bus);
        $events = $container->get(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        $commit = new \Ineersa\AgentCore\Application\Pipeline\RunCommit($active, $events, $dispatcher, new \Psr\Log\NullLogger(), new ToolBatchCollector(store: $container->get(ToolBatchStoreInterface::class)), $gate, $container->get(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class));
        $processor = new \Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor($active, $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class), $commit, $dispatcher, [$container->get(\Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler::class)]);
        $processor->process('result', $result);
        $batch = $container->get(ToolBatchStoreInterface::class)->load($run, 1, 'tools');
        $this->assertSame($stale ? 'Stale' : 'Consumed', array_values($batch->executionAuthorizations)[0]['state']);
        $this->assertSame([], $batch->pendingDispositions);
        $count = \count($bus->messages);
        $sequence = $events->latestSequenceFor($run);
        $processor->process('duplicate result', $result);
        $this->assertCount($count, $bus->messages);
        $this->assertSame($sequence, $events->latestSequenceFor($run));
        $this->assertNull($this->gate()->claim($call));
    }

    private function call(string $run): ExecuteToolCall
    {
        return new ExecuteToolCall(runId: $run, turnNo: 1, stepId: 'tools', attempt: 1, idempotencyKey: 'invocation', toolCallId: 'read-1', toolName: 'read', args: ['path' => 'fixture'], orderIndex: 0);
    }

    private function gate(): ToolExecutionAuthorization
    {
        return new ToolExecutionAuthorization(self::getContainer()->get(ToolBatchStoreInterface::class), self::getContainer()->get(SerializerInterface::class), self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class), self::getContainer()->get('hatfield.controller.session_owner.lock_factory'), self::getContainer()->get(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class), self::getContainer()->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class));
    }

    private function prepare(ExecuteToolCall $call): void
    {
        (new ToolBatchCollector(store: self::getContainer()->get(ToolBatchStoreInterface::class)))->registerExpectedBatch($call->runId(), 1, 'tools', [$call]);
        $this->gate()->arm($call);
    }

    private function worker(ToolExecutorInterface $executor, MessageBusInterface $bus, ToolExecutionAuthorization $gate): ExecuteToolCallWorker
    {
        return new ExecuteToolCallWorker($executor, $bus, $this->createStub(DeferredToolCompletionRepositoryInterface::class), new ToolExecutionResultStore(), $this->createStub(RunOperationalStatusReaderInterface::class), $gate);
    }
}
