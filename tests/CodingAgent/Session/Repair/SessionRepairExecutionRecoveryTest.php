<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Repair;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolExecutionAuthorization;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Session\DoctrineExecutionOperationStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\SerializerInterface;

final class SessionRepairExecutionRecoveryTest extends PerMethodIsolatedKernelTestCase
{
    public static function deliveries(): iterable
    {
        foreach (['llm', 'compaction', 'shell'] as $kind) {
            yield $kind.' armed' => [$kind, false];
            yield $kind.' ready' => [$kind, true];
        }
    }

    #[DataProvider('deliveries')]
    public function testRepairUsesOriginalAuthorizationAndFrozenDelivery(string $kind, bool $ready): void
    {
        $c = self::getContainer();
        $run = $c->get(HatfieldSessionStore::class)->createSession('repair delivery');
        $callId = 'sh_'.hash('sha256', 'shell-command');
        $request = match ($kind) {
            'llm' => new ExecuteLlmStep($run, 1, 'step', 1, 'key', 'original-tools', messages: []),
            'compaction' => new ExecuteCompactionStep($run, 1, 'step', 1, 'key', 'original-model', [], [], [], 0, 0, 0, 0, 'manual'),
            'shell' => new ExecuteShellToolCall($run, 1, 'step', 1, $callId, 'original command', true),
        };
        $events = [RunEvent::forAppend($run, 1, 'run_started', ['payload' => ['messages' => []]])];
        $identity = ['turn_no' => 1, 'step_id' => 'step', 'operation_attempt' => 1, 'operation_idempotency_key' => $request->idempotencyKey()];
        if ('shell' === $kind) {
            $events[] = RunEvent::forAppend($run, 1, 'agent_command_applied', ['kind' => 'shell_command', 'text' => '!different reconstructed command', 'idempotency_key' => 'shell-command', 'standalone' => true, 'current_operation' => $c->get(\Symfony\Component\Serializer\Normalizer\NormalizerInterface::class)->normalize(new \Ineersa\AgentCore\Domain\Run\CurrentOperationDTO(1, 'step', 1, 'shell-command'))]);
            $events[] = RunEvent::forAppend($run, 1, 'turn_advanced', [...$identity, 'operation_idempotency_key' => 'shell-command']);
        } else {
            $events[] = RunEvent::forAppend($run, 1, 'turn_advanced', $identity);
            if ('compaction' === $kind) {
                // No historical worker_request. Only the frozen operation file
                // may supply this input during repair.
                $events[] = RunEvent::forAppend($run, 1, 'context_compaction_started', $identity);
            }
        }
        $journal = $c->get(PreparedTransitionEventStoreInterface::class);
        $journal->appendTransition($events, ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $journal->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations = $c->get(DoctrineExecutionOperationStore::class);
        $stamp = $operations->arm($request, $pending);
        $reference = $operations->requestReference($request, $stamp);
        $journal->finalizeVerifiedTransition($run, $pending->identity);
        $expected = $reference;
        if ($ready) {
            $claim = $operations->claim($reference, $stamp);
            $this->assertIsString($claim);
            $result = match ($kind) {
                'llm' => new LlmStepResult($run, 1, 'step', 1, 'key', error: ['message' => 'original result']),
                'compaction' => new CompactionStepResult($run, 1, 'step', 1, 'key', 'original summary', null, [], 0, 0, 0, 0, 'manual'),
                'shell' => new ToolCallResult($run, 1, 'step', 1, $request->idempotencyKey(), $callId, 0, result: 'original result'),
            };
            $expected = $operations->saveResult($request, $stamp, $claim, $result);
        }
        $this->hydrate($run);
        $before = $journal->latestSequenceFor($run);
        $repair = $c->get(SessionRepairService::class);
        $this->assertSame(0, $repair->repair($run, false)->activeOperationsRedriven);
        $this->assertSame($before, $journal->latestSequenceFor($run));
        $applied = $repair->repair($run, true);
        $this->assertSame(1, $applied->activeOperationsRedriven, $applied->message);
        $this->assertSame(1, $repair->repair($run, true)->activeOperationsRedriven);
        $transport = $c->get('messenger.transport.'.($ready ? 'run_control' : ('shell' === $kind ? 'tool' : 'llm')));
        $sent = $transport->getSent();
        $this->assertCount(2, $sent);
        $this->assertEquals($expected, $sent[0]->getMessage());
        $this->assertEquals($expected, $sent[1]->getMessage());
        $this->assertSame($before, $journal->latestSequenceFor($run));
        if (!$ready) {
            $claim = $operations->claim($reference, $stamp);
            $this->assertIsString($claim);
            $this->assertEquals($request, $operations->resolveRequest($reference, $stamp, $claim));
        } else {
            $this->assertInstanceOf(DurableExecutionResult::class, $expected);
            $this->assertEquals($expected, $operations->claim($reference, $stamp));
        }
    }

    public static function toolDeliveries(): iterable
    {
        yield 'armed' => [false];
        yield 'ready' => [true];
    }

    #[DataProvider('toolDeliveries')]
    public function testOrdinaryToolRepairReusesBatchAuthorizationAndOriginalResult(bool $ready): void
    {
        $c = self::getContainer();
        $run = $c->get(HatfieldSessionStore::class)->createSession('repair ordinary delivery');
        $call = new ExecuteToolCall($run, 1, 'step', 1, 'key', 'call', 'read', ['path' => 'original-path'], 0);
        $batches = $c->get(ToolBatchStoreInterface::class);
        (new ToolBatchCollector(store: $batches))->registerExpectedBatch($run, 1, 'step', [$call]);
        $journal = $c->get(PreparedTransitionEventStoreInterface::class);
        $journal->appendTransition([
            RunEvent::forAppend($run, 1, 'run_started', ['payload' => ['messages' => []]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'step', 'operation_attempt' => 1, 'operation_idempotency_key' => 'key']),
            RunEvent::forAppend($run, 1, 'llm_step_completed', ['assistant_message' => ['role' => 'assistant', 'content' => [], 'tool_calls' => [['id' => 'call', 'function' => ['name' => 'read', 'arguments' => '{"path":"reconstructed-path"}']]]]]),
        ], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$call]]);
        $pending = $journal->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $gate = $c->get(ToolExecutionAuthorization::class);
        $gate->arm($call);
        $journal->finalizeVerifiedTransition($run, $pending->identity);
        $expected = $call;
        if ($ready) {
            $claim = $gate->claim($call);
            $this->assertIsString($claim);
            $expected = new ToolCallResult($run, 1, 'step', 1, 'key', 'call', 0, result: 'original durable result');
            $gate->saveResult($call, $claim, $expected);
        }
        $this->hydrate($run);
        $repair = $c->get(SessionRepairService::class);
        $this->assertSame(0, $repair->repair($run, false)->activeOperationsRedriven);
        $this->assertSame(1, $repair->repair($run, true)->activeOperationsRedriven);
        $this->assertSame(1, $repair->repair($run, true)->activeOperationsRedriven);
        $sent = $c->get('messenger.transport.'.($ready ? 'run_control' : 'tool'))->getSent();
        $this->assertCount(2, $sent);
        $this->assertEquals($expected, $sent[0]->getMessage());
        $this->assertEquals($expected, $sent[1]->getMessage());
        $this->assertSame(3, $journal->latestSequenceFor($run));
        if ($ready) {
            $this->assertEquals($expected, $gate->claim($call));
        } else {
            $this->assertIsString($gate->claim($call));
            $this->assertNull($gate->claim($call), 'Duplicate repaired delivery must not claim twice.');
        }
    }

    public static function authorities(): iterable
    {
        foreach (['generic' => false, 'ordinary tool' => true] as $name => $tool) {
            yield $name => [$tool, 'none'];
            yield $name.' live exclusion' => [$tool, 'live'];
            yield $name.' interrupted before retirement' => [$tool, 'before'];
            yield $name.' interrupted after retirement' => [$tool, 'after'];
        }
    }

    #[DataProvider('authorities')]
    public function testPreviewDoesNotRetireAndApplyPreservesWarningAndOldReceipt(bool $tool, string $boundary): void
    {
        $c = self::getContainer();
        $run = $c->get(HatfieldSessionStore::class)->createSession('repair unknown');
        $journal = $c->get(PreparedTransitionEventStoreInterface::class);
        $events = [RunEvent::forAppend($run, 1, 'run_started', ['payload' => ['messages' => []]])];
        if ($tool) {
            $events[] = RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'step', 'operation_attempt' => 1, 'operation_idempotency_key' => 'key']);
            $events[] = RunEvent::forAppend($run, 1, 'llm_step_completed', ['assistant_message' => ['role' => 'assistant', 'content' => [], 'tool_calls' => [['id' => 'call', 'function' => ['name' => 'read', 'arguments' => '{}']]]]]);
            $events[] = RunEvent::forAppend($run, 1, 'tool_execution_start', ['tool_call_id' => 'call', 'tool' => 'read', 'order_index' => 0]);
        }
        $events[] = RunEvent::forAppend($run, 1, 'agent_end', ['reason' => 'failed']);
        $journal->appendTransition($events, ['run_id' => $run, 'predecessor_seq' => 0]);
        $pending = $journal->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $journal->finalizeVerifiedTransition($run, $pending->identity);
        $before = $journal->latestSequenceFor($run);
        if ($tool) {
            $call = new ExecuteToolCall($run, 1, 'step', 1, 'key', 'call', 'read', [], 0);
            $batches = $c->get(ToolBatchStoreInterface::class);
            (new ToolBatchCollector(store: $batches))->registerExpectedBatch($run, 1, 'step', [$call]);
            $worker = new ToolExecutionAuthorization($batches, $c->get(SerializerInterface::class), $c->get(DeferredToolCompletionRepositoryInterface::class), $c->get('hatfield.controller.session_owner.lock_factory'), $c->get(DoctrineExecutionOperationStore::class), $c->get(RunLockManager::class));
            $worker->arm($call);
            $this->assertIsString($worker->claim($call));
            unset($worker);
            $gate = $c->get(ToolExecutionAuthorization::class);
            $gate->recoverRunning($run, 1, 'step');
            $notice = $gate->unknownExecutionsForRepair($run)[0];
        } else {
            $request = new ExecuteLlmStep($run, 1, 'step', 1, 'key', 'tools');
            $journal->appendTransition([], ['run_id' => $run, 'predecessor_seq' => $before, 'effects' => [$request]]);
            $pending = $journal->verifiedPendingTransition($run);
            $this->assertNotNull($pending);
            $worker = new DoctrineExecutionOperationStore($c->get(Connection::class), $c->get(ToolBatchRunStoragePathsInterface::class), new Filesystem(), $c->get('hatfield.controller.session_owner.lock_factory'), $c->get(RunLockManager::class), $c->get(ToolBatchStoreInterface::class));
            $stamp = $worker->arm($request, $pending);
            $reference = $worker->requestReference($request, $stamp);
            $this->assertIsString($worker->claim($reference, $stamp));
            $journal->finalizeVerifiedTransition($run, $pending->identity);
            unset($worker);
            $gate = $c->get(DoctrineExecutionOperationStore::class);
            $gate->pendingDeliveries($run, '');
            $notice = $gate->unknownExecutionsForRepair($run)[0];
        }
        $this->hydrate($run);
        if ('live' === $boundary) {
            // Exclusion is the authority even if a stored unknown receipt exists.
            $owner = explode('.', $notice->claimToken)[0];
            $lock = $c->get('hatfield.controller.session_owner.lock_factory')->createLock(($tool ? 'tool-execution-worker.' : 'execution-worker.').$owner, ttl: null);
            $this->assertTrue($lock->acquire());
            try {
                foreach ([false, true] as $apply) {
                    try {
                        $c->get(SessionRepairService::class)->repair($run, $apply);
                        $this->fail('Repair must refuse while the original worker exclusion is held.');
                    } catch (\RuntimeException $exception) {
                        $this->assertStringContainsString('original worker still owns execution', $exception->getMessage());
                    }
                    $this->assertSame($before, $journal->latestSequenceFor($run));
                    $this->assertCount(1, $gate->unknownExecutionsForRepair($run));
                }
            } finally {
                $lock->release();
            }
        }
        if (\in_array($boundary, ['before', 'after'], true)) {
            $coordination = $c->get(\Ineersa\AgentCore\Application\Handler\RetireUnknownExecutionHandler::class);
            $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
            $bus->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $action) use ($boundary, $coordination): never {
                if (!$action instanceof \Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO) {
                    throw new \LogicException('Unexpected repair action.');
                }
                if ('after' === $boundary) {
                    $coordination($action);
                }
                throw new \RuntimeException('Injected repair interruption.');
            });
            $c->set(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class, new \Ineersa\AgentCore\Application\Pipeline\RunCommit($c->get(ActiveRunContextInterface::class), $journal, new \Ineersa\AgentCore\Application\Handler\StepDispatcher($bus, new \Ineersa\AgentCore\Tests\Support\TestMessageBus()), new \Ineersa\AgentCore\Tests\Support\TestLogger(), $c->get(ToolBatchCollector::class), $c->get(ToolExecutionAuthorization::class), $c->get(DoctrineExecutionOperationStore::class)));
        }
        $repair = $c->get(SessionRepairService::class);
        $preview = $repair->repair($run, false);
        $this->assertStringContainsString('may duplicate', $preview->message);
        $this->assertCount(1, $gate->unknownExecutionsForRepair($run));
        $this->assertSame($before, $journal->latestSequenceFor($run));
        if (\in_array($boundary, ['before', 'after'], true)) {
            try {
                $repair->repair($run, true);
                $this->fail('The injected interruption must leave the repair recoverable.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Injected repair interruption.', $exception->getMessage());
            }
            $this->assertNotNull($journal->verifiedPendingTransition($run));
            $this->assertCount('before' === $boundary ? 1 : 0, $gate->unknownExecutionsForRepair($run));
            $c->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class)->recover($run);
            $c->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class)->recover($run);
            $this->assertNull($journal->verifiedPendingTransition($run));
            $this->hydrate($run);
            if ($tool) {
                $this->assertTrue($repair->repair($run, true)->staleCancellationRepaired, 'The interrupted repair must still finish the missing tool-message repair.');
            }
        } else {
            $result = $repair->repair($run, true);
            $this->assertTrue($result->staleCancellationRepaired);
            $this->assertStringContainsString('may duplicate', $result->message);
        }
        $this->assertSame([], $gate->unknownExecutionsForRepair($run));
        $this->assertFalse($gate->unknownNoticePending($notice));
        $this->assertNull($tool ? $gate->claim($call) : $gate->claim($reference, $stamp));
        $warnings = array_values(array_filter($journal->allFor($run), static fn (RunEvent $event): bool => 'execution_unknown_retired' === $event->type));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('may duplicate', $warnings[0]->payload['warning']);
        if ($tool) {
            $state = $c->get(ActiveRunContextInterface::class)->requireLoaded($run);
            $c->get(\Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator::class)->validate($state->messages);
            $this->assertSame('tool', $state->messages[array_key_last($state->messages)]->role);
            $batches->deleteAllForRun($run);
            $this->assertNotNull($batches->load($run, 1, 'step'), 'Cleanup must preserve the retired receipt decision.');
        }
        $this->assertFalse($repair->repair($run, true)->staleCancellationRepaired);
    }

    public static function attachedShellGenerations(): iterable
    {
        yield 'current attached shell' => [false];
        yield 'superseded attached shell' => [true];
    }

    #[DataProvider('attachedShellGenerations')]
    public function testRepairBeforeUnknownNoticeMatchesTheAttachedShellGeneration(bool $superseded): void
    {
        $c = self::getContainer();
        $run = $c->get(HatfieldSessionStore::class)->createSession('repair attached shell');
        $journal = $c->get(PreparedTransitionEventStoreInterface::class);
        $journal->appendTransition([
            RunEvent::forAppend($run, 1, 'run_started', ['payload' => ['messages' => []]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'model-step', 'operation_attempt' => 1, 'operation_idempotency_key' => 'model-key']),
        ], ['run_id' => $run, 'predecessor_seq' => 0]);
        $pending = $journal->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $journal->finalizeVerifiedTransition($run, $pending->identity);
        $this->hydrate($run);
        $c->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class)->process('test', new \Ineersa\AgentCore\Domain\Message\ApplyShellCommand($run, 1, 'shell-step', 1, 'shell-key', '!printf fixture'));
        $sent = $c->get('messenger.transport.tool')->getSent();
        $this->assertCount(1, $sent);
        $reference = $sent[0]->getMessage();
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecutionRequest::class, $reference);
        $stamp = $sent[0]->last(\Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp::class);
        $this->assertNotNull($stamp);
        $worker = new DoctrineExecutionOperationStore($c->get(Connection::class), $c->get(ToolBatchRunStoragePathsInterface::class), new Filesystem(), $c->get('hatfield.controller.session_owner.lock_factory'), $c->get(RunLockManager::class), $c->get(ToolBatchStoreInterface::class));
        $this->assertIsString($worker->claim($reference, $stamp));
        if ($superseded) {
            $journal->appendTransition([
                RunEvent::forAppend($run, 2, 'turn_advanced', ['turn_no' => 2, 'step_id' => 'new-model-step', 'operation_attempt' => 1, 'operation_idempotency_key' => 'new-model-key']),
            ], ['run_id' => $run, 'predecessor_seq' => $journal->latestSequenceFor($run)]);
            $pending = $journal->verifiedPendingTransition($run);
            $this->assertNotNull($pending);
            $journal->finalizeVerifiedTransition($run, $pending->identity);
        }
        unset($worker);
        $operations = $c->get(DoctrineExecutionOperationStore::class);
        $operations->pendingDeliveries($run, '');
        $notice = $operations->unknownExecutionsForRepair($run)[0];
        $this->hydrate($run);
        $before = $journal->latestSequenceFor($run);
        $repair = $c->get(SessionRepairService::class);
        $this->assertStringContainsString('may duplicate', $repair->repair($run, false)->message);
        $this->assertSame($before, $journal->latestSequenceFor($run));
        $this->assertStringContainsString('may duplicate', $repair->repair($run, true)->message);
        $state = $c->get(ActiveRunContextInterface::class)->requireLoaded($run);
        $this->assertSame($superseded ? \Ineersa\AgentCore\Domain\Run\RunStatus::Running : \Ineersa\AgentCore\Domain\Run\RunStatus::Failed, $state->status);
        $this->assertSame($superseded ? 'new-model-key' : 'model-key', $state->currentOperation?->idempotencyKey);
        $this->assertNull($operations->claim($reference, $stamp), 'Retired shell delivery must not execute again.');
        $this->assertSame([], $operations->unknownExecutionsForRepair($run));
        $this->assertFalse($operations->unknownNoticePending($notice));
        $delayed = $c->get(\Ineersa\AgentCore\Application\Pipeline\ExecutionOutcomeUnknownHandler::class)->handle($notice, $state);
        $this->assertSame([], $delayed->events, 'The retired notice must not fail the newer operation.');
        $tail = iterator_to_array($journal->rangeFor($run, $before + 1, $journal->latestSequenceFor($run)));
        $this->assertCount($superseded ? 1 : 2, $tail);
        $this->assertSame('execution_unknown_retired', $tail[0]->type);
    }

    private function hydrate(string $run): void
    {
        $c = self::getContainer();
        $state = $c->get(RunStateRebuilderInterface::class)->rebuildIfStale(RunState::queued($run), $run)->rebuiltState;
        $this->assertNotNull($state);
        $c->get(ActiveRunContextInterface::class)->loadRecovered($state);
    }
}
