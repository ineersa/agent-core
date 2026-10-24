<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\RedriveDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeAcknowledgementHandler;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Application\CodeMode\DTO\StopCodeModeDTO;
use Ineersa\CodingAgent\Application\Message\RepairSession;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Runtime\BackgroundWorkSnapshotProvider;
use Ineersa\CodingAgent\Runtime\Controller\CodeModeControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Process\Process;

final class CodeModeControllerSessionLifecycleListenerTest extends IsolatedKernelTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function queuedOutcomes(): iterable
    {
        yield 'admitted unconsumed background receipt' => [true];
    }

    #[DataProvider('queuedOutcomes')]
    public function testAlreadyQueuedOrAdmittedOutcomeRequiresExplicitRepair(bool $acknowledged): void
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'throw new RuntimeException("Do not rerun.");');
        $llmBefore = \count($c->get('messenger.transport.llm')->getSent());
        $run = $f->state->runId;
        $id = $f->registerReadyHost();
        if ($acknowledged) {
            $f->commit($f->state, $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($run, $id, 'cm', true), $f->state));
        }
        $result = new ToolCallResult($run, 1, 'step', 1, $f->call->idempotencyKey(), 'cm', 0, ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => 'first immutable output']]]);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $invocation = $deferred->findInvocationReferenceByDeferredId($id) ?? throw new \RuntimeException('Missing invocation.');
        $accepted = $c->get(ToolPayloadStoreInterface::class)->retainProcessedResult($invocation, $result);
        $deferred->acceptResult($id, $accepted);
        $c->get(CompleteDeferredToolCallHandler::class)->__invoke(new CompleteDeferredToolCall($id, []));
        // Unacknowledged settlement joins verified tool-result finalization.
        // Acknowledged work settles only after durable mailbox admission.
        $f->consumeCompletions();
        $this->assertSame('completed', $deferred->status($id), 'Verified finalization/admission precedes this restart barrier.');
        $transport = $c->get('messenger.transport.run_control');
        $before = $transport->getSent();
        $listener = $c->get(CodeModeControllerSessionLifecycleListener::class);
        $listener->starting(new ControllerSessionStartingEvent($run));
        $listener->starting(new ControllerSessionStartingEvent($run));
        $this->assertEquals($before, $transport->getSent(), 'Cold attachment never creates successor work.');
        $this->assertTrue($deferred->requiresContinuation($run, 'cm'));
        $this->assertEquals($accepted, $deferred->findAcceptedResultReferenceByDeferredId($id));
        $f->consumeCompletions(); // Drain any residual old envelopes after the fence.
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $this->assertCount(0, array_filter($events, static fn (RunEvent $e): bool => 'tool_execution_end' === $e->type));
        $state = $c->get(ActiveRunContextInterface::class)->requireLoaded($run);
        $c->get('agent.command.bus')->dispatch(new AdvanceRun($run, $state->turnNo, 'old-advance', 1, 'old-advance'), [new ReceivedStamp('run_control')]);
        $this->assertCount($llmBefore, $c->get('messenger.transport.llm')->getSent(), 'A delayed old successor cannot borrow the recovery notice to call the model.');
        $preview = $c->get(SessionRepairService::class)->repair($run, false, 'preview-controller-recovery');
        $this->assertNull($preview->refusalReason);
        $this->assertTrue($deferred->requiresContinuation($run, 'cm'), 'Preview does not authorize continuation.');
        $repairOffset = \count($transport->getSent());
        $c->get('agent.command.bus')->dispatch(new RepairSession($run, true, 'apply-controller-recovery'), [new ReceivedStamp('run_control')]);
        foreach (\array_slice($transport->getSent(), $repairOffset) as $envelope) {
            if ($envelope->getMessage() instanceof RedriveDeferredToolCall) {
                $c->get('agent.command.bus')->dispatch($envelope->with(new ReceivedStamp('run_control')));
            }
        }
        $f->consumeCompletions();
        $this->assertFalse($deferred->requiresContinuation($run, 'cm'));
        $this->assertTrue($c->get(CodeModeExecutionService::class)->cancellationToken($id, new NullCancellationToken())->isCancellationRequested(), 'Repair never reauthorizes the old host.');
        $this->assertSame([$id], $c->get(CommandStoreInterface::class)->pendingKeys($run), 'Repair reuses the admitted mailbox row.');
        $this->assertSame([], $c->get('messenger.transport.tool')->getSent(), 'Repair never repeats execution or processors.');
        foreach (\array_slice($transport->getSent(), $repairOffset) as $envelope) {
            if ($envelope->getMessage() instanceof AdvanceRun) {
                $c->get('agent.command.bus')->dispatch($envelope->with(new ReceivedStamp('run_control')));
            }
        }
        $this->assertCount($llmBefore + 1, $c->get('messenger.transport.llm')->getSent(), 'Explicit repair authorizes exactly one model continuation.');
        $this->assertSame([], $c->get(CommandStoreInterface::class)->pendingKeys($run));
        $c->get(CompleteDeferredToolCallHandler::class)->redrive(new RedriveDeferredToolCall($run, $id));
        $f->consumeCompletions();
        $this->assertCount($llmBefore + 1, $c->get('messenger.transport.llm')->getSent(), 'Duplicate repair cannot continue twice.');
    }

    public function testAcknowledgedHostWithoutReceiptParksUntilExplicitRepair(): void
    {
        // ACK before any completion receipt leaves an empty mailbox. Cold restart
        // must park continuation and refuse delayed AdvanceRun until prepared repair.
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'throw new RuntimeException("Do not rerun parked acknowledgement.");');
        $llmBefore = \count($c->get('messenger.transport.llm')->getSent());
        $run = $f->state->runId;
        $id = $f->registerReadyHost();
        $f->commit($f->state, $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($run, $id, 'cm', true), $f->state));
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $this->assertSame('pending', $deferred->status($id));
        $this->assertNull($deferred->findAcceptedResultReferenceByDeferredId($id));
        $this->assertSame([], $c->get(CommandStoreInterface::class)->pendingKeys($run));
        $transport = $c->get('messenger.transport.run_control');
        $before = $transport->getSent();
        $listener = $c->get(CodeModeControllerSessionLifecycleListener::class);
        $listener->starting(new ControllerSessionStartingEvent($run));
        $listener->starting(new ControllerSessionStartingEvent($run));
        $this->assertEquals($before, $transport->getSent(), 'Cold attachment never creates successor work for parked acknowledgements.');
        $this->assertTrue($deferred->requiresContinuation($run, 'cm'));
        $this->assertSame('pending', $deferred->status($id));
        $this->assertSame([], $c->get(CommandStoreInterface::class)->pendingKeys($run));
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $state = $f->state->with(['lastSeq' => $events[array_key_last($events)]->seq, 'pendingToolCalls' => ['cm' => true]]);
        $c->get(ActiveRunContextInterface::class)->loadRecovered($state);
        $c->get('agent.command.bus')->dispatch(new AdvanceRun($run, $state->turnNo, 'old-advance-empty', 1, 'old-advance-empty'), [new ReceivedStamp('run_control')]);
        $this->assertCount($llmBefore, $c->get('messenger.transport.llm')->getSent(), 'Empty-mailbox AdvanceRun cannot borrow parked recovery.');
        $preview = $c->get(SessionRepairService::class)->repair($run, false, 'preview-parked-ack');
        $this->assertNull($preview->refusalReason);
        $this->assertTrue($deferred->requiresContinuation($run, 'cm'), 'Preview does not authorize continuation.');
        $repairOffset = \count($transport->getSent());
        $c->get('agent.command.bus')->dispatch(new RepairSession($run, true, 'apply-parked-ack'), [new ReceivedStamp('run_control')]);
        foreach (\array_slice($transport->getSent(), $repairOffset) as $envelope) {
            if ($envelope->getMessage() instanceof RedriveDeferredToolCall) {
                $c->get('agent.command.bus')->dispatch($envelope->with(new ReceivedStamp('run_control')));
            }
        }
        $f->consumeCompletions();
        $this->assertFalse($deferred->requiresContinuation($run, 'cm'));
        $this->assertSame('completed', $deferred->status($id));
        $this->assertSame([$id], $c->get(CommandStoreInterface::class)->pendingKeys($run), 'Prepared repair admits the retained completion into the empty mailbox.');
        $afterRepair = $c->get(ActiveRunContextInterface::class)->requireLoaded($run);
        $c->get('agent.command.bus')->dispatch(new AdvanceRun($run, $afterRepair->turnNo, 'repair-advance', 1, 'repair-advance'), [new ReceivedStamp('run_control')]);
        $this->assertCount($llmBefore + 1, $c->get('messenger.transport.llm')->getSent(), 'Prepared repair authorizes model continuation after the empty-mailbox park.');
        $this->assertSame([], $c->get('messenger.transport.tool')->getSent(), 'Repair never repeats execution or processors.');
    }

    public function testFinalizedForegroundOutcomeIsNotReparkedOnControllerRestart(): void
    {
        // Unacknowledged settlement now joins verified tool-result finalization.
        // Once that commit lands, cold restart must not invent a parked recovery.
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'throw new RuntimeException("Do not rerun.");');
        $llmBefore = \count($c->get('messenger.transport.llm')->getSent());
        $run = $f->state->runId;
        $id = $f->registerReadyHost();
        $result = new ToolCallResult($run, 1, 'step', 1, $f->call->idempotencyKey(), 'cm', 0, ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => 'first immutable output']]]);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $invocation = $deferred->findInvocationReferenceByDeferredId($id) ?? throw new \RuntimeException('Missing invocation.');
        $accepted = $c->get(ToolPayloadStoreInterface::class)->retainProcessedResult($invocation, $result);
        $deferred->acceptResult($id, $accepted);
        $c->get(CompleteDeferredToolCallHandler::class)->__invoke(new CompleteDeferredToolCall($id, []));
        $f->consumeCompletions();
        $this->assertSame('completed', $deferred->status($id));
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $this->assertCount(1, array_filter($events, static fn (RunEvent $e): bool => 'tool_execution_end' === $e->type));
        $transport = $c->get('messenger.transport.run_control');
        $before = $transport->getSent();
        $listener = $c->get(CodeModeControllerSessionLifecycleListener::class);
        $listener->starting(new ControllerSessionStartingEvent($run));
        $listener->starting(new ControllerSessionStartingEvent($run));
        $this->assertEquals($before, $transport->getSent(), 'Cold attachment never creates successor work for finalized foreground results.');
        $this->assertFalse($deferred->requiresContinuation($run, 'cm'));
        $this->assertNull($deferred->findAcceptedResultReferenceByDeferredId($id));
        $this->assertCount($llmBefore, $c->get('messenger.transport.llm')->getSent());
        $this->assertSame([], $c->get('messenger.transport.tool')->getSent());
    }

    /** @return iterable<string, array{CodeModeInterruptionReasonEnum, bool}> */
    public static function abandonedCases(): iterable
    {
        yield 'cancelled-shutdown' => [CodeModeInterruptionReasonEnum::Cancelled, false];
        yield 'history-discarded-restart' => [CodeModeInterruptionReasonEnum::HistoryDiscarded, true];
    }

    #[DataProvider('abandonedCases')]
    public function testControllerLossRetiresAbandonedHostWithoutTerminalEvidence(CodeModeInterruptionReasonEnum $reason, bool $restart): void
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'throw new RuntimeException("Never rerun abandoned execution.");');
        $run = $f->state->runId;
        $id = $f->registerReadyHost();
        $f->commit($f->state, $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($run, $id, 'cm', true), $f->state));
        $executions = $c->get(CodeModeExecutionRepository::class);
        if (CodeModeInterruptionReasonEnum::Cancelled === $reason) {
            $c->get('agent.command.bus')->dispatch(new StopCodeModeDTO($run, $id, 'cm'), [new ReceivedStamp('run_control')]);
        } else {
            $executions->interrupt($id, $reason);
        }
        $this->assertSame('stopping', $c->get(BackgroundWorkSnapshotProvider::class)->snapshot($run)->work[0]->status);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $this->assertSame('pending', $deferred->status($id));
        $this->assertNull($executions->invocation($run, 'cm')['raw_reference']);
        $sent = \count($c->get('messenger.transport.run_control')->getSent());
        $llm = \count($c->get('messenger.transport.llm')->getSent());
        $listener = $c->get(CodeModeControllerSessionLifecycleListener::class);
        if ($restart) {
            $listener->starting(new ControllerSessionStartingEvent($run));
        } else {
            $listener->shutdown(new ControllerSessionShutdownEvent($run));
        }
        $listener->starting(new ControllerSessionStartingEvent($run));
        $c->get(CompleteDeferredToolCallHandler::class)->redrive(new RedriveDeferredToolCall($run, $id));
        $this->assertSame('completed', $deferred->status($id));
        $this->assertFalse($deferred->requiresContinuation($run, 'cm'));
        $this->assertNull($deferred->findAcceptedResultReferenceByDeferredId($id), 'Abandonment must not fabricate a successful or failed result.');
        $this->assertSame($reason->value, $executions->invocation($run, 'cm')['interruption_reason']);
        $this->assertNull($executions->invocation($run, 'cm')['raw_reference']);
        $this->assertSame([], $c->get(BackgroundWorkSnapshotProvider::class)->snapshot($run)->work);
        $observed = $c->get(BackgroundWorkSnapshotProvider::class)->snapshot($run, observedCodeModeIdentity: $id)->work[0];
        $this->assertTrue($observed->terminal);
        $this->assertSame('interrupted', $observed->status);
        $this->assertNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($run, 1, 'step'));
        $this->assertNull($c->get(DeferredToolCompletionRepositoryInterface::class)->findAcceptedResultReferenceByDeferredId($id));
        $this->assertCount($sent, $c->get('messenger.transport.run_control')->getSent());
        $this->assertCount($llm, $c->get('messenger.transport.llm')->getSent());
        $this->assertSame([], array_filter($c->get(PreparedTransitionEventStoreInterface::class)->allFor($run), static fn (RunEvent $event): bool => 'tool_execution_end' === $event->type));
    }

    /** @return iterable<string, array{bool}> */
    public static function coldRecoveryCases(): iterable
    {
        yield 'active invocations need explicit continuation' => [false];
        yield 'abandoned invocations retire without continuation' => [true];
    }

    #[DataProvider('coldRecoveryCases')]
    public function testColdProcessRecoversWaitingRootAndAcknowledgedOwnedChild(bool $abandoned): void
    {
        // A second kernel in another process must recover committed evidence,
        // not live fixture objects. Cold schema/kernel boot uses a 15-second
        // safety cap; no product delay or time window synchronizes the test.
        $directory = TestDirectoryIsolation::createProjectTempDir('code-mode-cold-recovery');
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Support/code-mode-controller-recovery-process.php', $directory, $abandoned ? 'setup-abandoned' : 'setup'], env: ['HATFIELD_SESSION_ID' => false]);
        try {
            $process->setTimeout(15);
            $process->mustRun();
            $lines = explode("\n", trim($process->getOutput()));
            $evidence = json_decode($lines[array_key_last($lines)], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertCount(2, $evidence);
            foreach ($evidence as $row) {
                $this->assertSame('completed', $row['status']);
                if ($abandoned) {
                    $this->assertSame(0, $row['ends']);
                    $this->assertSame(1, $row['acks']);
                    $this->assertNull($row['accepted']);
                    $this->assertNull($row['raw']);
                    $this->assertSame($row['root'] ? 'cancelled' : 'history_discarded', $row['reason']);
                    $this->assertNull($row['batch']);
                    $this->assertSame([], $row['work']);
                    $this->assertSame(0, $row['sent']);
                    $this->assertSame(0, $row['llm']);

                    continue;
                }
                $this->assertSame($row['root'] ? 1 : 0, $row['ends']);
                $this->assertSame($row['root'] ? 0 : 1, $row['acks']);
                $this->assertSame('controller_interrupted', $row['error']);
                $this->assertTrue($row['raw_exists']);
            }
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testFailedAbandonedRetirementPreservesPendingMetadataAndBatchForRetry(): void
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'throw new RuntimeException("Never rerun.");');
        $run = $f->state->runId;
        $id = $f->registerReadyHost();
        $f->commit($f->state, $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($run, $id, 'cm', true), $f->state));
        $c->get(CodeModeExecutionRepository::class)->interrupt($id, CodeModeInterruptionReasonEnum::HistoryDiscarded);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $deferred->requireContinuation($id, true);
        $reference = $deferred->findInvocationReferenceByDeferredId($id);
        $connection = $c->get(Connection::class);
        $connection->executeStatement("CREATE TEMP TRIGGER code_mode_abandon_abort BEFORE UPDATE OF status ON tool_deferred_completion BEGIN SELECT RAISE(ABORT, 'abandoned retirement rollback'); END");
        $listener = $c->get(CodeModeControllerSessionLifecycleListener::class);
        try {
            $listener->starting(new ControllerSessionStartingEvent($run));
            $this->fail('Retirement metadata must roll back.');
        } catch (DriverException $error) {
            $this->assertStringContainsString('abandoned retirement rollback', $error->getMessage());
        } finally {
            $connection->executeStatement('DROP TRIGGER code_mode_abandon_abort');
        }
        $this->assertSame('pending', $deferred->status($id));
        $this->assertTrue($deferred->requiresContinuation($run, 'cm'));
        $this->assertNotNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($run, 1, 'step'));
        $this->assertEquals($f->call, $c->get(ToolPayloadStoreInterface::class)->read($reference), 'Immutable cleanup cannot precede metadata commit.');
        $listener->starting(new ControllerSessionStartingEvent($run));
        $this->assertSame('completed', $deferred->status($id));
        $this->assertFalse($deferred->requiresContinuation($run, 'cm'));
        $this->assertNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($run, 1, 'step'));
    }

    public function testFailedRecoveryTransactionCannotLeaveAnAcceptanceOrFenceBehind(): void
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'throw new RuntimeException("Do not rerun.");');
        $service = $c->get(CodeModeExecutionService::class);
        $id = $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($f->state->runId, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), $service->begin(...));
        $connection = $c->get(Connection::class);
        $connection->executeStatement("CREATE TEMP TRIGGER code_mode_recovery_abort BEFORE UPDATE OF interruption_reason ON code_mode_execution BEGIN SELECT RAISE(ABORT, 'controller recovery rollback'); END");
        $listener = $c->get(CodeModeControllerSessionLifecycleListener::class);
        try {
            $listener->starting(new ControllerSessionStartingEvent($f->state->runId));
            $this->fail('Recovery transaction must fail.');
        } catch (DriverException $error) {
            $this->assertStringContainsString('controller recovery rollback', $error->getMessage());
        } finally {
            $connection->executeStatement('DROP TRIGGER code_mode_recovery_abort');
        }
        $this->assertNull($c->get(DeferredToolCompletionRepositoryInterface::class)->findAcceptedResultReferenceByDeferredId($id));
        $this->assertNull($c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm')['interruption_reason']);
        $listener->starting(new ControllerSessionStartingEvent($f->state->runId));
        $this->assertTrue($c->get(DeferredToolCompletionRepositoryInterface::class)->requiresContinuation($f->state->runId, 'cm'));
        $c->get(CompleteDeferredToolCallHandler::class)->redrive(new RedriveDeferredToolCall($f->state->runId, $id));
        $f->consumeCompletions();
        $this->assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($f->state->runId);
        $this->assertCount(1, array_filter($events, static fn (RunEvent $e): bool => 'tool_execution_end' === $e->type));
    }

    /** @return iterable<string, array{bool, bool, bool, bool}> */
    public static function recoveryCases(): iterable
    {
        yield 'root-waiting-processed' => [false, false, true, true];
        yield 'root-waiting-no-processed' => [false, false, false, true];
        yield 'root-acknowledged-processed' => [false, true, true, true];
        yield 'owned-child-acknowledged-processed' => [true, true, true, true];
        yield 'root-unready-waiting' => [false, false, false, false];
    }

    #[DataProvider('recoveryCases')]
    public function testRecoveryUsesFirstEvidenceOrTruthfulFailureExactlyOnce(bool $child, bool $acknowledged, bool $processed, bool $ready): void
    {
        $c = self::getContainer();
        $owner = null;
        $run = null;
        if ($child) {
            $owner = $c->get(HatfieldSessionStore::class)->createSession('controller owner');
            $run = 'owned-child-'.$owner;
            $entry = $c->get(AgentArtifactRegistry::class)->create($owner, 'owned-child', $run, 'fork', AgentArtifactKindEnum::Fork);
            $c->get(AgentChildRunDirectory::class)->register($entry);
        }
        $f = new CodeModeBackendFixture($c, 'throw new \\RuntimeException("Execution must not be rerun.");', runId: $run);
        $owner ??= $f->state->runId;
        $run = $f->state->runId;
        $service = $c->get(CodeModeExecutionService::class);
        $id = $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($run, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), $service->begin(...));
        if ($ready) {
            $service->ready($id);
        }
        if ($acknowledged) {
            // ACK must precede the host's terminal evidence, even when owner
            // acceptance and controller recovery happen later.
            $f->commit($f->state, $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($run, $id, 'cm', true), $f->state));
        }
        $service->preserve($id, ['value' => 42], null);
        $raw = $c->get(CodeModeExecutionRepository::class)->invocation($run, 'cm')['raw_reference'];
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $original = new ToolCallResult($run, 1, 'step', 1, $f->call->idempotencyKey(), 'cm', 0,
            ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => 'retained original']], 'details' => ['stdout' => 'OUT', 'stderr' => 'ERR']], true, ['type' => 'original_failure', 'message' => 'original failure', 'retryable' => false]);
        if ($processed) {
            $reference = $deferred->findInvocationReferenceByDeferredId($id);
            $c->get(ToolPayloadStoreInterface::class)->retainProcessedResult($reference, $original);
        }
        $listener = $c->get(CodeModeControllerSessionLifecycleListener::class);
        $event = new ControllerSessionStartingEvent($owner);
        $sentBefore = \count($c->get('messenger.transport.run_control')->getSent());
        $listener->starting($event);
        $acceptedJson = $c->get(Connection::class)->fetchOne('SELECT accepted_result_reference_json FROM tool_deferred_completion WHERE deferred_id = ?', [$id]);
        $this->assertIsString($acceptedJson);
        $accepted = $service->resultReference($acceptedJson);
        $terminal = $c->get(ToolPayloadStoreInterface::class)->read($accepted);
        $this->assertTrue($terminal->isError);
        $this->assertSame($processed ? 'original_failure' : 'controller_interrupted', $terminal->error['type']);
        if ($processed) {
            $this->assertEquals($original, $terminal, 'Recovery must not reformat or reprocess the retained result.');
        } else {
            $this->assertStringContainsString('external effects may already have occurred', $terminal->error['message']);
            $this->assertFalse($terminal->error['retryable']);
        }
        $listener->starting($event); // A send may be queued but not admitted yet.
        $this->assertCount($sentBefore, $c->get('messenger.transport.run_control')->getSent(), 'Passive recovery must not dispatch continuation or completion work.');
        $this->assertTrue($deferred->requiresContinuation($run, 'cm'));
        $this->assertSame('pending', $deferred->status($id));
        $c->get(CompleteDeferredToolCallHandler::class)->redrive(new RedriveDeferredToolCall($run, $id));
        $f->consumeCompletions();
        $this->assertSame('completed', $deferred->status($id));
        $listener->starting($event);
        $f->consumeCompletions();
        $this->assertSame($acceptedJson, $c->get(Connection::class)->fetchOne('SELECT accepted_result_reference_json FROM tool_deferred_completion WHERE deferred_id = ?', [$id]));
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $this->assertCount($acknowledged ? 0 : 1, array_filter($events, static fn (RunEvent $e): bool => 'tool_execution_end' === $e->type));
        $this->assertCount($acknowledged ? 1 : 0, array_filter($events, static fn (RunEvent $e): bool => 'tool_call_acknowledged' === $e->type));
        $this->assertSame('controller_restarted', $c->get(CodeModeExecutionRepository::class)->invocation($run, 'cm')['interruption_reason']);
        $this->assertFileExists($c->get(CodeModeResultArtifactStore::class)->path($service->resultReference($raw)));
        $this->assertTrue($service->cancellationToken($id, new NullCancellationToken())->isCancellationRequested());
    }
}
