<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Repair;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Session\DoctrineExecutionOperationStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;

final class SessionRepairExecutionRecoveryTest extends PerMethodIsolatedKernelTestCase
{
    public static function deliveries(): iterable
    {
        foreach (['llm', 'compaction', 'shell'] as $kind) {
            yield $kind.' armed' => [$kind, false];
            yield $kind.' ready' => [$kind, true];
        }
        yield 'llm lost broker acknowledgement' => ['llm', false, true];
    }

    #[DataProvider('deliveries')]
    public function testRepairUsesOriginalAuthorizationAndFrozenDelivery(string $kind, bool $ready, bool $lostAcknowledgement = false): void
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
        $operations->prepare($request, $pending);
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
        $this->assertSame(0, $repair->repair($run, false, \Symfony\Component\Uid\Uuid::v4()->toRfc4122())->activeOperationsRedriven);
        $this->assertSame($before, $journal->latestSequenceFor($run));
        if ($lostAcknowledgement) {
            // Cut and source acceptance finish before broker send. A failed send
            // leaves the exact Armed ledger obligation for retry without a new authorization.
            $appliedLost = $repair->repair($run, true, 'original-repair');
            $this->assertSame(1, $appliedLost->activeOperationsRedriven, $appliedLost->message);
            $this->assertNull($journal->verifiedPendingTransition($run));
            $source = \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance::actionIdentity(\Ineersa\CodingAgent\Application\Message\RepairSession::class, $run, 'original-repair');
            $this->assertTrue($c->get(\Ineersa\AgentCore\Application\Pipeline\SourceAcceptance::class)->identityAlreadyAccepted($source));
            $fail = true;
            $broker = $this->createStub(\Symfony\Component\Messenger\MessageBusInterface::class);
            $broker->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$fail): \Symfony\Component\Messenger\Envelope {
                if ($fail) {
                    $fail = false;
                    throw new \RuntimeException('Injected lost broker acknowledgement.');
                }

                return new \Symfony\Component\Messenger\Envelope($message, $stamps);
            });
            $publication = new \Ineersa\AgentCore\Application\Pipeline\DurablePendingPublication(
                $journal,
                $operations,
                $c->get(\Ineersa\AgentCore\Contract\ControlMessageOutboxInterface::class),
                $c->get(RunLockManager::class),
                $c->get('agent.command.bus'),
                $broker,
                new \Ineersa\AgentCore\Tests\Support\TestLogger(),
            );
            $publication->publishReady($run);
            $this->assertNotNull($operations->pendingDeliveriesForRun($run)[$reference->effectId] ?? null);
            $publication->publishReady($run);
            $this->assertSame(1, (int) $c->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM execution_operation WHERE run_id = ?', [$run]), 'Retry must not create another authorization.');
        }
        $applied = $repair->repair($run, true, 'original-repair');
        $this->assertSame($lostAcknowledgement ? 0 : 1, $applied->activeOperationsRedriven, $applied->message);
        $c->get('cache.app')->clear();
        $c->get(ActiveRunContextInterface::class)->release($run);
        $this->hydrate($run);
        $this->assertSame(0, $repair->repair($run, true, 'original-repair')->activeOperationsRedriven);
        $this->assertSame(1, $repair->repair($run, true, 'fresh-repair')->activeOperationsRedriven);
        $transport = $c->get('messenger.transport.'.($ready ? 'run_control' : ('shell' === $kind ? 'tool' : 'llm')));
        $sent = $transport->getSent();
        $this->assertCount(2, $sent);
        $this->assertEquals($expected, $sent[0]->getMessage());
        $this->assertEquals($expected, $sent[1]->getMessage());
        $this->assertSame($before, $journal->latestSequenceFor($run));
        if (!$ready) {
            $this->assertEquals($stamp, $sent[0]->last(\Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp::class));
            $this->assertEquals($stamp, $sent[1]->last(\Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp::class));
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
        $answer = new ToolCallHumanInputAnswerDTO('q-1', ['approved' => true], ['run_id' => $run], ['hook' => 'approval']);
        $i0 = new ExecuteToolCall($run, 1, 'step', 1, 'i0-key', 'call', 'read', ['path' => 'original-path'], 0);
        $i1 = $i0->withAuthorizedHumanAnswer($answer);
        $this->assertSame(2, $i1->attempt());
        $this->assertNotSame('i0-key', $i1->idempotencyKey());
        $batches = $c->get(ToolBatchStoreInterface::class);
        $journal = $c->get(PreparedTransitionEventStoreInterface::class);
        $journal->appendTransition([
            RunEvent::forAppend($run, 1, 'run_started', ['payload' => ['messages' => []]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'step', 'operation_attempt' => 1, 'operation_idempotency_key' => 'model-key']),
            RunEvent::forAppend($run, 1, 'llm_step_completed', ['step_id' => 'step', 'assistant_message' => ['role' => 'assistant', 'content' => [], 'tool_calls' => [['id' => 'call', 'function' => ['name' => 'read', 'arguments' => '{"path":"reconstructed-path"}']]]]]),
            RunEvent::forAppend($run, 1, 'tool_execution_start', ['tool_call_id' => 'call', 'tool' => 'read', 'order_index' => 0, 'attempt' => 2]),
        ], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$i1], 'actions' => [new RegisterToolBatchDTO($run, 1, 'step', [$i1], [$i1->toolCallId => 0], [], [$i1->toolCallId => true], 1)]]);
        $pending = $journal->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations = $c->get(DoctrineExecutionOperationStore::class);
        $operations->prepare($i1, $pending);
        $batches->registerPrepared(new RegisterToolBatchDTO($run, 1, 'step', [$i1], [$i1->toolCallId => 0], [], [$i1->toolCallId => true], 1), $pending);
        $this->assertEquals($i1, $batches->load($run, 1, 'step')?->calls['call'] ?? null);
        $stamp = $operations->arm($i1, $pending);
        $reference = $operations->requestReference($i1, $stamp);
        $journal->finalizeVerifiedTransition($run, $pending->identity);
        $expected = $reference;
        if ($ready) {
            $claim = $operations->claim($reference, $stamp);
            $this->assertIsString($claim);
            $expected = $operations->saveResult($i1, $stamp, $claim, new ToolCallResult($run, 1, 'step', 2, $i1->idempotencyKey(), 'call', 0, result: 'original durable result'));
        }
        $this->hydrate($run);
        $repair = $c->get(SessionRepairService::class);
        $this->assertSame(0, $repair->repair($run, false, \Symfony\Component\Uid\Uuid::v4()->toRfc4122())->activeOperationsRedriven);
        $this->assertSame(1, $repair->repair($run, true, 'original-repair')->activeOperationsRedriven);
        $c->get('cache.app')->clear();
        $this->assertSame(0, $repair->repair($run, true, 'original-repair')->activeOperationsRedriven);
        $this->assertSame(1, $repair->repair($run, true, 'fresh-repair')->activeOperationsRedriven);
        $sent = $c->get('messenger.transport.'.($ready ? 'run_control' : 'tool'))->getSent();
        $this->assertCount(2, $sent);
        $this->assertEquals($expected, $sent[0]->getMessage());
        $this->assertEquals($expected, $sent[1]->getMessage());
        $this->assertSame(4, $journal->latestSequenceFor($run));
        $redriven = $sent[0]->getMessage();
        if ($ready) {
            $this->assertInstanceOf(DurableExecutionResult::class, $redriven);
            $this->assertSame(2, $redriven->attempt());
            $this->assertSame($i1->idempotencyKey(), $redriven->idempotencyKey());
            $this->assertEquals($expected, $operations->claim($reference, $stamp));
        } else {
            $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecutionRequest::class, $redriven);
            $this->assertSame(2, $redriven->attempt());
            $this->assertSame($i1->idempotencyKey(), $redriven->idempotencyKey());
            $this->assertIsString($operations->claim($reference, $stamp));
            $this->assertNull($operations->claim($reference, $stamp), 'Duplicate repaired delivery must not claim twice.');
        }
    }

    public function testMatchingI1UnknownNoticeFailsCurrentRunAndExplicitRepairRetiresExactReceipt(): void
    {
        $c = self::getContainer();
        $run = $c->get(HatfieldSessionStore::class)->createSession('repair matching i1');
        $answer = new ToolCallHumanInputAnswerDTO('q-1', ['approved' => true], ['run_id' => $run], ['hook' => 'approval']);
        $i0 = new ExecuteToolCall($run, 1, 'step', 1, 'i0-key', 'call', 'read', ['path' => 'approved-path'], 0);
        $i1 = $i0->withAuthorizedHumanAnswer($answer);
        $batches = $c->get(ToolBatchStoreInterface::class);
        $journal = $c->get(PreparedTransitionEventStoreInterface::class);
        $journal->appendTransition([
            RunEvent::forAppend($run, 1, 'run_started', ['payload' => ['messages' => []]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'step', 'operation_attempt' => 1, 'operation_idempotency_key' => 'model-key']),
            RunEvent::forAppend($run, 1, 'llm_step_completed', ['step_id' => 'step', 'assistant_message' => ['role' => 'assistant', 'content' => [], 'tool_calls' => [['id' => 'call', 'function' => ['name' => 'read', 'arguments' => '{"path":"approved-path"}']]]]]),
            RunEvent::forAppend($run, 1, 'tool_execution_start', ['tool_call_id' => 'call', 'tool' => 'read', 'order_index' => 0, 'attempt' => 2]),
        ], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$i0]]);
        $pending = $journal->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations = $c->get(DoctrineExecutionOperationStore::class);
        $operations->prepare($i0, $pending);
        $i0Stamp = $operations->arm($i0, $pending);
        $i0Reference = $operations->requestReference($i0, $i0Stamp);
        $journal->finalizeVerifiedTransition($run, $pending->identity);
        $i0Claim = $operations->claim($i0Reference, $i0Stamp);
        $this->assertIsString($i0Claim);
        $question = \Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO::toolCallFromPayload(
            ['question_id' => 'q-1', 'prompt' => 'Allow?'],
            ['run_id' => $run, 'turn_no' => 1, 'step_id' => 'step', 'tool_call_id' => 'call'],
        );
        $operations->saveResult($i0, $i0Stamp, $i0Claim, (new ToolCallResult($run, 1, 'step', 1, 'i0-key', 'call', 0, null, false, null, $question))->finalized());

        $journal->appendTransition([], ['run_id' => $run, 'predecessor_seq' => $journal->latestSequenceFor($run), 'effects' => [$i1], 'actions' => [new RegisterToolBatchDTO($run, 1, 'step', [$i1], [$i1->toolCallId => 0], [], [$i1->toolCallId => true], 1)]]);
        $pending = $journal->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations->prepare($i1, $pending);
        $batches->registerPrepared(new RegisterToolBatchDTO($run, 1, 'step', [$i1], [$i1->toolCallId => 0], [], [$i1->toolCallId => true], 1), $pending);
        $this->assertEquals($i1, $batches->load($run, 1, 'step')?->calls['call'] ?? null);
        $i1Stamp = $operations->arm($i1, $pending);
        $i1Reference = $operations->requestReference($i1, $i1Stamp);
        $journal->finalizeVerifiedTransition($run, $pending->identity);
        $worker = new DoctrineExecutionOperationStore($c->get(Connection::class), $c->get(ToolBatchRunStoragePathsInterface::class), new Filesystem(), $c->get('hatfield.controller.session_owner.lock_factory'), $c->get(RunLockManager::class), $c->get(PreparedTransitionEventStoreInterface::class), $c->get(DeferredToolCompletionRepositoryInterface::class));
        $this->assertIsString($worker->claim($i1Reference, $i1Stamp));
        unset($worker);
        $operations->pendingDeliveries($run, '');
        $notice = $operations->unknownExecutionsForRepair($run)[0];
        $this->assertSame(2, $notice->attempt());
        $this->assertSame($i1->idempotencyKey(), $notice->idempotencyKey());
        $this->assertTrue($operations->matchesCurrentAuthorizedToolInvocation($notice, $i1));
        $this->assertFalse($operations->matchesCurrentAuthorizedToolInvocation($notice, $i0));

        $this->hydrate($run);
        $state = $c->get(ActiveRunContextInterface::class)->requireLoaded($run);
        $failed = $c->get(\Ineersa\AgentCore\Application\Pipeline\ExecutionOutcomeUnknownHandler::class)->handle($notice, $state);
        $this->assertSame(RunStatus::Failed, $failed->nextState?->status);
        $this->assertSame(ExecutionOutcomeUnknown::ERROR_MESSAGE, $failed->nextState?->errorMessage);
        $this->assertCount(1, $failed->events);

        $before = $journal->latestSequenceFor($run);
        $projectionBefore = $c->get(Connection::class)->fetchAssociative('SELECT effect_id, state, attempt, idempotency_key FROM execution_operation WHERE effect_id = ?', [$notice->effectId]);
        $repair = $c->get(SessionRepairService::class);
        $preview = $repair->repair($run, false, 'matching-preview');
        $this->assertNull($preview->refusalReason);
        $this->assertStringContainsString('may duplicate', $preview->message);
        $this->assertSame($before, $journal->latestSequenceFor($run));
        $this->assertSame($projectionBefore, $c->get(Connection::class)->fetchAssociative('SELECT effect_id, state, attempt, idempotency_key FROM execution_operation WHERE effect_id = ?', [$notice->effectId]));

        $applied = $repair->repair($run, true, 'matching-repair');
        $this->assertNull($applied->refusalReason);
        $this->assertNotSame(SessionRepairRefusalReasonEnum::AmbiguousPendingWork, $applied->refusalReason);
        $this->assertTrue($applied->staleCancellationRepaired);
        $this->assertStringContainsString('may duplicate', $applied->message);
        $this->assertSame([], $operations->unknownExecutionsForRepair($run));
        $this->assertFalse($operations->unknownNoticePending($notice));
        $this->assertNull($operations->claim($i1Reference, $i1Stamp));
        $retired = $c->get(Connection::class)->fetchAssociative('SELECT state, attempt, idempotency_key FROM execution_operation WHERE effect_id = ?', [$notice->effectId]);
        $this->assertSame('Stale', $retired['state']);
        $this->assertSame(2, (int) $retired['attempt']);
        $this->assertSame($i1->idempotencyKey(), $retired['idempotency_key']);
        $this->hydrate($run);
        $terminal = $c->get(ActiveRunContextInterface::class)->requireLoaded($run);
        $this->assertSame(RunStatus::Failed, $terminal->status);
        $warnings = array_values(array_filter($journal->allFor($run), static fn (RunEvent $event): bool => 'execution_unknown_retired' === $event->type));
        $this->assertCount(1, $warnings);
        $this->assertSame([$notice->effectId], $warnings[0]->payload['execution_ids']);
    }

    public function testRefusedNestedUnknownRepairDoesNotMutateOrExecuteLeadingAction(): void
    {
        $c = self::getContainer();
        $run = $c->get(HatfieldSessionStore::class)->createSession('repair nested refusal');
        $call = new ExecuteToolCall($run, 1, 'step', 1, 'key', 'call', 'read', [], 0);
        $batches = $c->get(ToolBatchStoreInterface::class);
        $journal = $c->get(PreparedTransitionEventStoreInterface::class);
        $journal->appendTransition([
            RunEvent::forAppend($run, 1, 'run_started', ['payload' => ['messages' => []]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'step', 'operation_attempt' => 1, 'operation_idempotency_key' => 'model-key']),
            RunEvent::forAppend($run, 1, 'llm_step_completed', ['step_id' => 'step', 'assistant_message' => ['role' => 'assistant', 'content' => [], 'tool_calls' => [['id' => 'call', 'function' => ['name' => 'read', 'arguments' => '{}']]]]]),
            RunEvent::forAppend($run, 1, 'tool_execution_start', ['tool_call_id' => 'call', 'tool' => 'read', 'order_index' => 0, 'attempt' => 1]),
        ], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$call], 'actions' => [new RegisterToolBatchDTO($run, 1, 'step', [$call], [$call->toolCallId => 0], [], [$call->toolCallId => true], 1)]]);
        $pending = $journal->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations = $c->get(DoctrineExecutionOperationStore::class);
        $operations->prepare($call, $pending);
        $batches->registerPrepared(new RegisterToolBatchDTO($run, 1, 'step', [$call], [$call->toolCallId => 0], [], [$call->toolCallId => true], 1), $pending);
        $journal->finalizeVerifiedTransition($run, $pending->identity);
        $before = $journal->latestSequenceFor($run);

        $stale = new ExecuteToolCall($run, 1, 'older-step', 1, 'stale-key', 'call', 'read', [], 0);
        $journal->appendTransition([], ['run_id' => $run, 'predecessor_seq' => $before, 'effects' => [$stale]]);
        $pending = $journal->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations->prepare($stale, $pending);
        $stamp = $operations->arm($stale, $pending);
        $reference = $operations->requestReference($stale, $stamp);
        $journal->finalizeVerifiedTransition($run, $pending->identity);
        $worker = new DoctrineExecutionOperationStore($c->get(Connection::class), $c->get(ToolBatchRunStoragePathsInterface::class), new Filesystem(), $c->get('hatfield.controller.session_owner.lock_factory'), $c->get(RunLockManager::class), $c->get(PreparedTransitionEventStoreInterface::class), $c->get(DeferredToolCompletionRepositoryInterface::class));
        $this->assertIsString($worker->claim($reference, $stamp));
        unset($worker);
        $operations->pendingDeliveries($run, '');
        $notice = $operations->unknownExecutionsForRepair($run)[0];
        $this->assertSame('older-step', $notice->stepId());

        $this->hydrate($run);
        $archiveBefore = $journal->allFor($run);
        $projectionBefore = $c->get(Connection::class)->fetchAllAssociative('SELECT effect_id, state, claim_token, unknown_notice_transition, disposition_transition FROM execution_operation WHERE run_id = ? ORDER BY effect_id', [$run]);
        $batchBefore = $batches->load($run, 1, 'step');
        $commands = $c->get(CommandStoreInterface::class);
        $sentinel = new EnqueueCommandDTO(new PendingCommand($run, 'follow_up', 'nested-refusal-sentinel', ['text' => 'must-not-enqueue']));
        $this->assertFalse($commands->has($run, 'nested-refusal-sentinel'));

        $repair = $c->get(SessionRepairService::class);
        $result = $repair->repair($run, true, 'nested-refusal', [$sentinel]);
        $this->assertSame(SessionRepairRefusalReasonEnum::AmbiguousPendingWork, $result->refusalReason);
        $this->assertFalse($result->staleCancellationRepaired);
        $this->assertEquals($archiveBefore, $journal->allFor($run));
        $this->assertSame($projectionBefore, $c->get(Connection::class)->fetchAllAssociative('SELECT effect_id, state, claim_token, unknown_notice_transition, disposition_transition FROM execution_operation WHERE run_id = ? ORDER BY effect_id', [$run]));
        $this->assertEquals($batchBefore, $batches->load($run, 1, 'step'));
        $this->assertCount(1, $operations->unknownExecutionsForRepair($run));
        $this->assertTrue($operations->unknownNoticePending($notice));
        $this->assertFalse($commands->has($run, 'nested-refusal-sentinel'));
        $this->assertSame([], $commands->pending($run));
        $this->assertNull($journal->verifiedPendingTransition($run));
    }

    public static function authorities(): iterable
    {
        foreach (['generic' => false] as $name => $tool) {
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
        $events[] = RunEvent::forAppend($run, 1, 'agent_end', ['reason' => 'failed']);
        $journal->appendTransition($events, ['run_id' => $run, 'predecessor_seq' => 0]);
        $pending = $journal->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $journal->finalizeVerifiedTransition($run, $pending->identity);
        $before = $journal->latestSequenceFor($run);
        $this->assertFalse($tool, 'Ordinary-tool unknown matching proof lives in testMatchingI1UnknownNoticeFailsCurrentRunAndExplicitRepairRetiresExactReceipt.');
        $request = new ExecuteLlmStep($run, 1, 'step', 1, 'key', 'tools');
        $journal->appendTransition([], ['run_id' => $run, 'predecessor_seq' => $before, 'effects' => [$request]]);
        $pending = $journal->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $worker = new DoctrineExecutionOperationStore($c->get(Connection::class), $c->get(ToolBatchRunStoragePathsInterface::class), new Filesystem(), $c->get('hatfield.controller.session_owner.lock_factory'), $c->get(RunLockManager::class), $c->get(PreparedTransitionEventStoreInterface::class), $c->get(DeferredToolCompletionRepositoryInterface::class));
        $worker->prepare($request, $pending);
        $stamp = $worker->arm($request, $pending);
        $reference = $worker->requestReference($request, $stamp);
        $journal->finalizeVerifiedTransition($run, $pending->identity);
        $this->assertIsString($worker->claim($reference, $stamp));
        unset($worker);
        $gate = $c->get(DoctrineExecutionOperationStore::class);
        $gate->pendingDeliveries($run, '');
        $notice = $gate->unknownExecutionsForRepair($run)[0];
        $this->hydrate($run);
        if ('live' === $boundary) {
            // Exclusion is the authority even if a stored unknown receipt exists.
            $owner = explode('.', $notice->claimToken)[0];
            $lock = $c->get('hatfield.controller.session_owner.lock_factory')->createLock('execution-worker.'.$owner, ttl: null);
            $this->assertTrue($lock->acquire());
            try {
                foreach ([false, true] as $apply) {
                    try {
                        $c->get(SessionRepairService::class)->repair($run, $apply, 'repair-id');
                        $this->fail('Repair must refuse while the original worker exclusion is held.');
                    } catch (\RuntimeException $exception) {
                        $this->assertStringContainsString('original worker still owns execution', $exception->getMessage());
                    }
                    $this->assertSame($before + 0, $journal->latestSequenceFor($run));
                    $this->assertCount(1, $gate->unknownExecutionsForRepair($run));
                }
            } finally {
                $lock->release();
            }
        }
        if (\in_array($boundary, ['before', 'after'], true)) {
            // Retirement is local metadata under the owner transaction, not a bus action.
            $inner = $c->get(DoctrineExecutionOperationStore::class);
            $operations = new class($inner, $boundary) implements \Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface {
                public function __construct(private DoctrineExecutionOperationStore $inner, private string $boundary)
                {
                }

                public function retireUnknownExecution(\Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO $action, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
                {
                    if ('after' === $this->boundary) {
                        $this->inner->retireUnknownExecution($action, $transition);
                    }
                    throw new \RuntimeException('Injected repair interruption.');
                }

                public function unknownExecutionsForRepair(string $runId): array
                {
                    return $this->inner->unknownExecutionsForRepair($runId);
                }

                public function assertUnknownRepairable(ExecutionOutcomeUnknown $notice): void
                {
                    $this->inner->assertUnknownRepairable($notice);
                }

                public function matchesCurrentAuthorizedToolInvocation(ExecutionOutcomeUnknown $notice, ExecuteToolCall $authorizedCall): bool
                {
                    return $this->inner->matchesCurrentAuthorizedToolInvocation($notice, $authorizedCall);
                }

                public function repairDelivery(string $runId, \Ineersa\AgentCore\Domain\Run\CurrentOperationDTO $operation, string $requestType): ?\Symfony\Component\Messenger\Envelope
                {
                    return $this->inner->repairDelivery($runId, $operation, $requestType);
                }

                public function assertRequestCapacity(\Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $request): void
                {
                    $this->inner->assertRequestCapacity($request);
                }

                public function prepare(\Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $request, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp
                {
                    return $this->inner->prepare($request, $transition);
                }

                public function arm(\Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $request, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp
                {
                    return $this->inner->arm($request, $transition);
                }

                public function requestReference(\Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $request, \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp $authorization): \Ineersa\AgentCore\Domain\Message\ExecutionRequest
                {
                    return $this->inner->requestReference($request, $authorization);
                }

                public function claim(\Ineersa\AgentCore\Domain\Message\ExecutionRequest $request, \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp $authorization): string|DurableExecutionResult|null
                {
                    return $this->inner->claim($request, $authorization);
                }

                public function resolveRequest(\Ineersa\AgentCore\Domain\Message\ExecutionRequest $reference, \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp $authorization, string $claim): \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage
                {
                    return $this->inner->resolveRequest($reference, $authorization, $claim);
                }

                public function peekRequest(\Ineersa\AgentCore\Domain\Message\ExecutionRequest $reference): \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage
                {
                    return $this->inner->peekRequest($reference);
                }

                public function resultForClaim(\Ineersa\AgentCore\Domain\Message\ExecutionRequest $reference, \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp $authorization, string $claim): DurableExecutionResult
                {
                    return $this->inner->resultForClaim($reference, $authorization, $claim);
                }

                public function saveResult(\Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $request, \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp $authorization, string $claim, \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $result): DurableExecutionResult
                {
                    return $this->inner->saveResult($request, $authorization, $claim, $result);
                }

                public function transferToDeferred(\Ineersa\AgentCore\Domain\Message\ExecutionRequest $reference, \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp $authorization, string $claim, string $deferredId): void
                {
                    $this->inner->transferToDeferred($reference, $authorization, $claim, $deferredId);
                }

                public function saveDeferredResult(string $deferredId, \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $result): DurableExecutionResult
                {
                    return $this->inner->saveDeferredResult($deferredId, $result);
                }

                public function resolveResult(DurableExecutionResult $reference): \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage
                {
                    return $this->inner->resolveResult($reference);
                }

                public function isDisposed(DurableExecutionResult $reference): bool
                {
                    return $this->inner->isDisposed($reference);
                }

                public function validateDisposition(\Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO $descriptor, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
                {
                    $this->inner->validateDisposition($descriptor, $transition);
                }

                public function applyDisposition(\Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO $descriptor, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
                {
                    $this->inner->applyDisposition($descriptor, $transition);
                }

                public function reclaimDisposedPayloads(string $ownerSessionId, string $afterEffectId): string
                {
                    return $this->inner->reclaimDisposedPayloads($ownerSessionId, $afterEffectId);
                }

                public function pendingDeliveriesForRun(string $runId): array
                {
                    return $this->inner->pendingDeliveriesForRun($runId);
                }

                public function pendingDeliveries(string $ownerSessionId, string $afterEffectId): array
                {
                    return $this->inner->pendingDeliveries($ownerSessionId, $afterEffectId);
                }

                public function retireUnstartedPermissions(string $runId, int $turnNo, string $stepId, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
                {
                    $this->inner->retireUnstartedPermissions($runId, $turnNo, $stepId, $transition);
                }

                public function unknownNoticePending(ExecutionOutcomeUnknown $notice): bool
                {
                    return $this->inner->unknownNoticePending($notice);
                }

                public function consumeUnknownNotice(\Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO $action, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
                {
                    $this->inner->consumeUnknownNotice($action, $transition);
                }

                public function assertNoUnknownExecution(string $runId): void
                {
                    $this->inner->assertNoUnknownExecution($runId);
                }
            };
            $finalizer = TestTransitionFinalizerFactory::create(
                $journal,
                new \Ineersa\AgentCore\Application\Handler\StepDispatcher(new \Ineersa\AgentCore\Tests\Support\TestMessageBus()),
                operations: $operations,
                batches: $c->get(ToolBatchStoreInterface::class),
                commands: $c->get(CommandStoreInterface::class),
                sourceAcceptance: $c->get(\Ineersa\AgentCore\Application\Pipeline\SourceAcceptance::class),
                outbox: $c->get(\Ineersa\AgentCore\Contract\ControlMessageOutboxInterface::class),
            );
            $c->set(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class, new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
                activeRunContext: $c->get(ActiveRunContextInterface::class),
                eventStore: $journal,
                logger: new \Ineersa\AgentCore\Tests\Support\TestLogger(),
                executionOperations: $operations,
                sourceAcceptance: $c->get(\Ineersa\AgentCore\Application\Pipeline\SourceAcceptance::class),
                finalizer: $finalizer,
            ));
        }
        $repair = $c->get(SessionRepairService::class);
        $preview = $repair->repair($run, false, 'repair-id');
        $this->assertStringContainsString('may duplicate', $preview->message);
        $this->assertCount(1, $gate->unknownExecutionsForRepair($run));
        $this->assertSame($before + 0, $journal->latestSequenceFor($run));
        if (\in_array($boundary, ['before', 'after'], true)) {
            try {
                $repair->repair($run, true, 'repair-id');
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
        } else {
            $result = $repair->repair($run, true, 'repair-id');
            $this->assertTrue($result->staleCancellationRepaired);
            $this->assertStringContainsString('may duplicate', $result->message);
        }
        $this->assertSame([], $gate->unknownExecutionsForRepair($run));
        $this->assertFalse($gate->unknownNoticePending($notice));
        $this->assertNull($gate->claim($reference, $stamp));
        $warnings = array_values(array_filter($journal->allFor($run), static fn (RunEvent $event): bool => 'execution_unknown_retired' === $event->type));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('may duplicate', $warnings[0]->payload['warning']);
        $this->assertFalse($repair->repair($run, true, 'repair-id')->staleCancellationRepaired);
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
        $worker = new DoctrineExecutionOperationStore($c->get(Connection::class), $c->get(ToolBatchRunStoragePathsInterface::class), new Filesystem(), $c->get('hatfield.controller.session_owner.lock_factory'), $c->get(RunLockManager::class), $c->get(PreparedTransitionEventStoreInterface::class), $c->get(DeferredToolCompletionRepositoryInterface::class));
        $this->assertIsString($worker->claim($reference, $stamp));
        if ($superseded) {
            $next = new ExecuteLlmStep($run, 2, 'new-model-step', 1, 'new-model-key', 'tools', messages: []);
            $journal->appendTransition([
                RunEvent::forAppend($run, 2, 'turn_advanced', ['turn_no' => 2, 'step_id' => 'new-model-step', 'operation_attempt' => 1, 'operation_idempotency_key' => 'new-model-key']),
            ], ['run_id' => $run, 'predecessor_seq' => $journal->latestSequenceFor($run), 'effects' => [$next]]);
            $pending = $journal->verifiedPendingTransition($run);
            $this->assertNotNull($pending);
            $c->get(DoctrineExecutionOperationStore::class)->prepare($next, $pending);
            $c->get(DoctrineExecutionOperationStore::class)->arm($next, $pending);
            $journal->finalizeVerifiedTransition($run, $pending->identity);
        }
        unset($worker);
        $operations = $c->get(DoctrineExecutionOperationStore::class);
        $operations->pendingDeliveries($run, '');
        $notice = $operations->unknownExecutionsForRepair($run)[0];
        $this->hydrate($run);
        $before = $journal->latestSequenceFor($run);
        $repair = $c->get(SessionRepairService::class);
        $this->assertStringContainsString('may duplicate', $repair->repair($run, false, \Symfony\Component\Uid\Uuid::v4()->toRfc4122())->message);
        $this->assertSame($before, $journal->latestSequenceFor($run));
        $this->assertStringContainsString('may duplicate', $repair->repair($run, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122())->message);
        $state = $c->get(ActiveRunContextInterface::class)->requireLoaded($run);
        $this->assertSame($superseded ? RunStatus::Running : RunStatus::Failed, $state->status);
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
