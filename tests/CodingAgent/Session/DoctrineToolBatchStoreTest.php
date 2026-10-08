<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Application\Pipeline\SourceAcceptance;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Session\DoctrineExecutionOperationStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/** SQL scheduling admits only in-flight calls; local metadata rolls back together. */
final class DoctrineToolBatchStoreTest extends IsolatedKernelTestCase
{
    public function testQueuedCallStaysPreparedUntilCapacityFreesAndMetadataRollbackIsAtomic(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('sql scheduling admission');
        $first = new ExecuteToolCall($run, 1, 'tools', 1, 'key-a', 'call-a', 'echo', ['command' => 'a'], 0, mode: ToolExecutionMode::Parallel->value, maxParallelism: 1, batchToolCallCount: 2);
        $second = new ExecuteToolCall($run, 1, 'tools', 1, 'key-b', 'call-b', 'echo', ['command' => 'b'], 1, mode: ToolExecutionMode::Parallel->value, maxParallelism: 1, batchToolCallCount: 2);
        $mailbox = new EnqueueCommandDTO(new PendingCommand($run, 'follow_up', 'mailbox-admit', ['text' => 'keep']));
        $source = SourceAcceptance::identity(new \Ineersa\AgentCore\Domain\Message\AdvanceRun($run, 1, 'tools', 1, 'advance-admit'));
        $register = new RegisterToolBatchDTO($run, 1, 'tools', [$first, $second], ['call-a' => 0, 'call-b' => 1], ['call-b'], ['call-a' => true], 1);
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $operations = $container->get(ExecutionOperationStoreInterface::class);
        $batches = $container->get(ToolBatchStoreInterface::class);
        $commands = $container->get(CommandStoreInterface::class);
        $connection = $container->get(\Doctrine\DBAL\Connection::class);
        $coordinator = $container->get(LocalMetadataCoordinator::class);

        $events->appendTransition([], [
            'run_id' => $run,
            'predecessor_seq' => 0,
            'source' => $source,
            'effects' => [],
            'actions' => [$register, $mailbox],
        ]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);

        $coordinator->apply($this->plan($run, $pending, [$register, $mailbox]));
        $deliveries = $batches->admittedCalls($run, 1, 'tools');
        $events->finalizeVerifiedTransition($run, $pending->identity);

        $schedule = $batches->load($run, 1, 'tools');
        $this->assertNotNull($schedule);
        $this->assertSame(['call-a' => true], $schedule->inFlight);
        $this->assertSame(['call-b'], $schedule->pendingQueue);
        $this->assertCount(1, $deliveries);
        $this->assertSame($first->idempotencyKey(), $deliveries[0]->idempotencyKey());

        $firstAuth = new \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp(
            (string) $connection->fetchOne('SELECT effect_id FROM execution_operation WHERE idempotency_key = ?', ['key-a']),
            (string) $connection->fetchOne('SELECT request_hash FROM execution_operation WHERE idempotency_key = ?', ['key-a']),
        );
        $secondEffect = (string) $connection->fetchOne('SELECT effect_id FROM execution_operation WHERE idempotency_key = ?', ['key-b']);
        $secondHash = (string) $connection->fetchOne('SELECT request_hash FROM execution_operation WHERE idempotency_key = ?', ['key-b']);
        $this->assertSame('Armed', $connection->fetchOne('SELECT state FROM execution_operation WHERE idempotency_key = ?', ['key-a']));
        $this->assertSame('Prepared', $connection->fetchOne('SELECT state FROM execution_operation WHERE idempotency_key = ?', ['key-b']));
        $this->assertNull($operations->claim(new ExecutionRequest($run, 1, 'tools', 1, 'key-b', $secondEffect, ExecuteToolCall::class, $secondHash, (int) $connection->fetchOne('SELECT request_bytes FROM execution_operation WHERE idempotency_key = ?', ['key-b'])), new \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp($secondEffect, $secondHash)));
        $pendingDeliveries = $container->get(DoctrineExecutionOperationStore::class)->pendingDeliveries($run, '');
        $this->assertArrayHasKey($firstAuth->effectId, $pendingDeliveries);
        $this->assertArrayNotHasKey($secondEffect, $pendingDeliveries);
        $this->assertTrue($commands->has($run, 'mailbox-admit'));
        $this->assertTrue($commands->has($run, $this->acceptedSourceKey($source)));
        $frozenSecond = $operations->peekRequest(new ExecutionRequest($run, 1, 'tools', 1, 'key-b', $secondEffect, ExecuteToolCall::class, $secondHash, (int) $connection->fetchOne('SELECT request_bytes FROM execution_operation WHERE idempotency_key = ?', ['key-b'])));
        $this->assertEquals($second, $frozenSecond);

        $failingCommands = new class($commands) implements CommandStoreInterface {
            public function __construct(private CommandStoreInterface $inner)
            {
            }

            public function enqueue(PendingCommand $command): bool
            {
                throw new \RuntimeException('Injected mailbox failure.');
            }

            public function has(string $runId, string $idempotencyKey): bool
            {
                return $this->inner->has($runId, $idempotencyKey);
            }

            public function pending(string $runId): array
            {
                return $this->inner->pending($runId);
            }

            public function countPending(string $runId): int
            {
                return $this->inner->countPending($runId);
            }

            public function markApplied(string $runId, string $idempotencyKey): void
            {
                $this->inner->markApplied($runId, $idempotencyKey);
            }

            public function markRejected(string $runId, string $idempotencyKey, string $reason): void
            {
                $this->inner->markRejected($runId, $idempotencyKey, $reason);
            }
        };
        $rollbackRun = $container->get(HatfieldSessionStore::class)->createSession('sql scheduling rollback');
        $rollbackFirst = new ExecuteToolCall($rollbackRun, 1, 'tools', 1, 'roll-a', 'call-a', 'echo', ['command' => 'a'], 0, mode: ToolExecutionMode::Parallel->value, maxParallelism: 1, batchToolCallCount: 2);
        $rollbackSecond = new ExecuteToolCall($rollbackRun, 1, 'tools', 1, 'roll-b', 'call-b', 'echo', ['command' => 'b'], 1, mode: ToolExecutionMode::Parallel->value, maxParallelism: 1, batchToolCallCount: 2);
        $rollbackMailbox = new EnqueueCommandDTO(new PendingCommand($rollbackRun, 'follow_up', 'mailbox-roll', ['text' => 'rollback']));
        $rollbackSource = SourceAcceptance::identity(new \Ineersa\AgentCore\Domain\Message\AdvanceRun($rollbackRun, 1, 'tools', 1, 'advance-roll'));
        $rollbackRegister = new RegisterToolBatchDTO($rollbackRun, 1, 'tools', [$rollbackFirst, $rollbackSecond], ['call-a' => 0, 'call-b' => 1], ['call-b'], ['call-a' => true], 1);
        $events->appendTransition([], [
            'run_id' => $rollbackRun,
            'predecessor_seq' => 0,
            'source' => $rollbackSource,
            'actions' => [$rollbackRegister, $rollbackMailbox],
        ]);
        $rollbackPending = $events->verifiedPendingTransition($rollbackRun);
        $this->assertNotNull($rollbackPending);
        $rollbackCoordinator = new LocalMetadataCoordinator(
            $container->get(\Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface::class),
            $batches,
            $failingCommands,
            $operations,
            new SourceAcceptance($failingCommands),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\DurablePendingPublication::class),
        );
        try {
            $rollbackCoordinator->apply($this->plan($rollbackRun, $rollbackPending, [$rollbackRegister, $rollbackMailbox]));
            $this->fail('Mailbox failure must roll back local metadata.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected mailbox failure.', $exception->getMessage());
        }
        $this->assertNull($batches->load($rollbackRun, 1, 'tools'));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM execution_operation WHERE run_id = ? AND state = ?', [$rollbackRun, 'Armed']));
        $this->assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM execution_operation WHERE run_id = ? AND state = ?', [$rollbackRun, 'Prepared']));
        $this->assertFalse($commands->has($rollbackRun, 'mailbox-roll'));
        $this->assertFalse($commands->has($rollbackRun, $this->acceptedSourceKey($rollbackSource)));
        $coordinator->apply($this->plan($rollbackRun, $rollbackPending, [$rollbackRegister, $rollbackMailbox]));
        $replayDeliveries = $batches->admittedCalls($rollbackRun, 1, 'tools');
        $events->finalizeVerifiedTransition($rollbackRun, $rollbackPending->identity);
        $this->assertCount(1, $replayDeliveries);
        $this->assertSame('roll-a', $replayDeliveries[0]->idempotencyKey());
        $this->assertSame('Armed', $connection->fetchOne('SELECT state FROM execution_operation WHERE idempotency_key = ?', ['roll-a']));
        $this->assertSame('Prepared', $connection->fetchOne('SELECT state FROM execution_operation WHERE idempotency_key = ?', ['roll-b']));

        $complete = new FinalizeToolBatchDTO($run, 1, 'tools', pendingQueue: [], inFlight: ['call-b' => true], awaitingHumanInput: [], finalized: false);
        $events->appendTransition([], [
            'run_id' => $run,
            'predecessor_seq' => 0,
            'source' => SourceAcceptance::identity(new \Ineersa\AgentCore\Domain\Message\AdvanceRun($run, 1, 'tools', 2, 'advance-free')),
            'actions' => [$complete],
        ]);
        $free = $events->verifiedPendingTransition($run);
        $this->assertNotNull($free);
        $coordinator->apply($this->plan($run, $free, [$complete]));
        $freed = $batches->admittedCalls($run, 1, 'tools');
        $events->finalizeVerifiedTransition($run, $free->identity);
        $this->assertCount(1, $freed);
        $this->assertSame('key-b', $freed[0]->idempotencyKey());
        $this->assertSame('Armed', $connection->fetchOne('SELECT state FROM execution_operation WHERE idempotency_key = ?', ['key-b']));
        $this->assertEquals($second, $operations->peekRequest(new ExecutionRequest($run, 1, 'tools', 1, 'key-b', $secondEffect, ExecuteToolCall::class, $secondHash, (int) $connection->fetchOne('SELECT request_bytes FROM execution_operation WHERE idempotency_key = ?', ['key-b']))));
        $this->assertArrayHasKey($secondEffect, $container->get(DoctrineExecutionOperationStore::class)->pendingDeliveries($run, ''));

        // Producer-shaped postCommitEffects can include an already-frozen Running
        // sibling. Prepare stays idempotent; arm activates only Prepared permission.
        $claim = $operations->claim(new ExecutionRequest($run, 1, 'tools', 1, 'key-b', $secondEffect, ExecuteToolCall::class, $secondHash, (int) $connection->fetchOne('SELECT request_bytes FROM execution_operation WHERE idempotency_key = ?', ['key-b'])), new \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp($secondEffect, $secondHash));
        $this->assertIsString($claim);
        $this->assertSame('Running', $connection->fetchOne('SELECT state FROM execution_operation WHERE effect_id = ?', [$secondEffect]));
        $sealCountBefore = (int) $connection->fetchOne('SELECT COUNT(*) FROM execution_operation WHERE run_id = ?', [$run]);
        $third = new ExecuteToolCall($run, 1, 'tools', 1, 'key-c', 'call-c', 'echo', ['command' => 'c'], 2, mode: ToolExecutionMode::Parallel->value, maxParallelism: 2, batchToolCallCount: 3);
        $prepareC = SourceAcceptance::identity(new \Ineersa\AgentCore\Domain\Message\AdvanceRun($run, 1, 'tools', 5, 'advance-prepare-c'));
        $events->appendTransition([], [
            'run_id' => $run,
            'predecessor_seq' => 0,
            'source' => $prepareC,
            'effects' => [$third],
        ]);
        $preparePending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($preparePending);
        $stampC = $operations->prepare($third, $preparePending);
        $operations->prepare($third, $preparePending); // idempotent exact-hash reuse
        $events->finalizeVerifiedTransition($run, $preparePending->identity);
        $thirdEffect = $stampC->effectId;
        $this->assertSame('Prepared', $connection->fetchOne('SELECT state FROM execution_operation WHERE effect_id = ?', [$thirdEffect]));
        $this->assertSame($sealCountBefore + 1, (int) $connection->fetchOne('SELECT COUNT(*) FROM execution_operation WHERE run_id = ?', [$run]));

        $schedule = $batches->load($run, 1, 'tools');
        $this->assertNotNull($schedule);
        $batches->delete($run, 1, 'tools');
        $extended = new RegisterToolBatchDTO(
            $run,
            1,
            'tools',
            [$first, $second, $third],
            ['call-a' => 0, 'call-b' => 1, 'call-c' => 2],
            ['call-c'],
            ['call-b' => true],
            2,
        );
        $extendPendingSource = SourceAcceptance::identity(new \Ineersa\AgentCore\Domain\Message\AdvanceRun($run, 1, 'tools', 55, 'advance-extend-c'));
        $events->appendTransition([], [
            'run_id' => $run,
            'predecessor_seq' => 0,
            'source' => $extendPendingSource,
            'actions' => [$extended],
        ]);
        $extendPending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($extendPending);
        $batches->registerPrepared($extended, $extendPending);
        $events->finalizeVerifiedTransition($run, $extendPending->identity);

        $admitC = new FinalizeToolBatchDTO($run, 1, 'tools', pendingQueue: [], inFlight: ['call-b' => true, 'call-c' => true], awaitingHumanInput: [], finalized: false);
        $events->appendTransition([], [
            'run_id' => $run,
            'predecessor_seq' => 0,
            'source' => SourceAcceptance::identity(new \Ineersa\AgentCore\Domain\Message\AdvanceRun($run, 1, 'tools', 6, 'advance-admit-c')),
            'actions' => [$admitC],
            'effects' => [$second], // producer shape: frozen Running sibling still present
            'post_commit_effects' => [$second],
        ]);
        $admitPending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($admitPending);
        $coordinator->apply($this->plan($run, $admitPending, [$admitC], [$second]));
        $admitted = array_values(array_filter($batches->admittedCalls($run, 1, 'tools'), static fn ($c) => 'key-c' === $c->idempotencyKey()));
        $events->finalizeVerifiedTransition($run, $admitPending->identity);
        $this->assertCount(1, $admitted);
        $this->assertSame('key-c', $admitted[0]->idempotencyKey());
        $this->assertSame($secondEffect, (string) $connection->fetchOne('SELECT effect_id FROM execution_operation WHERE idempotency_key = ?', ['key-b']));
        $this->assertSame('Running', $connection->fetchOne('SELECT state FROM execution_operation WHERE effect_id = ?', [$secondEffect]));
        $this->assertSame('Armed', $connection->fetchOne('SELECT state FROM execution_operation WHERE effect_id = ?', [$thirdEffect]));
        $this->assertSame($sealCountBefore + 1, (int) $connection->fetchOne('SELECT COUNT(*) FROM execution_operation WHERE run_id = ?', [$run]));

        $result = \Ineersa\AgentCore\Application\Handler\ToolCallResultFactory::fromExecuteToolCallAndToolResult(
            $second,
            new \Ineersa\AgentCore\Domain\Tool\ToolResult('echo', 'echo', [['type' => 'text', 'text' => 'b']]),
        );
        $reference = $operations->saveResult($second, new \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp($secondEffect, $secondHash), $claim, $result);
        $descriptor = new \Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO($reference, 'Consumed');
        $consumeAction = new FinalizeToolBatchDTO($run, 1, 'tools', pendingQueue: [], inFlight: ['call-c' => true], awaitingHumanInput: [], finalized: false, result: $result);
        $events->appendTransition([], [
            'run_id' => $run,
            'predecessor_seq' => 0,
            'source' => SourceAcceptance::identity(new \Ineersa\AgentCore\Domain\Message\AdvanceRun($run, 1, 'tools', 7, 'advance-consume-b')),
            'execution_disposition' => $descriptor,
            'actions' => [$consumeAction],
        ]);
        $consumePending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($consumePending);
        $coordinator->apply($this->plan($run, $consumePending, [$consumeAction], [], $descriptor));
        $events->finalizeVerifiedTransition($run, $consumePending->identity);
        $payloadDir = \dirname($container->get(\Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface::class)->resolveToolBatchesDirectory($run)).'/execution-operations/'.$secondEffect;
        $this->assertDirectoryExists($payloadDir);
        $this->assertSame($secondEffect, $operations->reclaimDisposedPayloads($run, ''));
        $this->assertDirectoryExists($payloadDir, 'Active ordered buffer must retain consumed seal.');

        $cancel = new FinalizeToolBatchDTO($run, 1, 'tools', pendingQueue: [], inFlight: [], awaitingHumanInput: [], finalized: true);
        $events->appendTransition([], [
            'run_id' => $run,
            'predecessor_seq' => 0,
            'source' => SourceAcceptance::identity(new \Ineersa\AgentCore\Domain\Message\AdvanceRun($run, 1, 'tools', 8, 'advance-cancel')),
            'actions' => [$cancel],
        ]);
        $cancelPending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($cancelPending);
        $coordinator->apply($this->plan($run, $cancelPending, [$cancel]));
        $events->finalizeVerifiedTransition($run, $cancelPending->identity);
        $this->assertSame('Stale', $connection->fetchOne('SELECT state FROM execution_operation WHERE effect_id = ?', [$thirdEffect]));
        $thirdHash = (string) $connection->fetchOne('SELECT request_hash FROM execution_operation WHERE effect_id = ?', [$thirdEffect]);
        $this->assertNull($operations->claim(new ExecutionRequest($run, 1, 'tools', 1, 'key-c', $thirdEffect, ExecuteToolCall::class, $thirdHash, (int) $connection->fetchOne('SELECT request_bytes FROM execution_operation WHERE effect_id = ?', [$thirdEffect])), new \Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp($thirdEffect, $thirdHash)));
        $this->assertSame('Consumed', $connection->fetchOne('SELECT state FROM execution_operation WHERE effect_id = ?', [$secondEffect]));
    }

    /**
     * @param list<object> $actions
     * @param list<object> $effects
     */
    private function plan(string $runId, object $pending, array $actions, array $effects = [], ?\Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO $disposition = null): TransitionPlan
    {
        return new TransitionPlan($runId, $pending, $actions, [], [], array_values(array_filter($effects, static fn ($e) => $e instanceof \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage)), $disposition);
    }

    /** @param array<string, int|string> $source */
    private function acceptedSourceKey(array $source): string
    {
        return 'accepted-source:'.hash('sha256', serialize([$source['type'], $source['run_id'], $source['turn_no'], $source['step_id'], $source['attempt'], $source['idempotency_key']]));
    }
}
