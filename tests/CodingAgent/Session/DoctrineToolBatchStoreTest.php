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
        $register = new RegisterToolBatchDTO($run, 1, 'tools', [$first, $second]);
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

        [$deliveries] = $coordinator->apply($pending, [$register, $mailbox]);
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
        $rollbackRegister = new RegisterToolBatchDTO($rollbackRun, 1, 'tools', [$rollbackFirst, $rollbackSecond]);
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
        );
        try {
            $rollbackCoordinator->apply($rollbackPending, [$rollbackRegister, $rollbackMailbox]);
            $this->fail('Mailbox failure must roll back local metadata.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected mailbox failure.', $exception->getMessage());
        }
        $this->assertNull($batches->load($rollbackRun, 1, 'tools'));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM execution_operation WHERE run_id = ? AND state = ?', [$rollbackRun, 'Armed']));
        $this->assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM execution_operation WHERE run_id = ? AND state = ?', [$rollbackRun, 'Prepared']));
        $this->assertFalse($commands->has($rollbackRun, 'mailbox-roll'));
        $this->assertFalse($commands->has($rollbackRun, $this->acceptedSourceKey($rollbackSource)));
        [$replayDeliveries] = $coordinator->apply($rollbackPending, [$rollbackRegister, $rollbackMailbox]);
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
        [$freed] = $coordinator->apply($free, [$complete]);
        $events->finalizeVerifiedTransition($run, $free->identity);
        $this->assertCount(1, $freed);
        $this->assertSame('key-b', $freed[0]->idempotencyKey());
        $this->assertSame('Armed', $connection->fetchOne('SELECT state FROM execution_operation WHERE idempotency_key = ?', ['key-b']));
        $this->assertEquals($second, $operations->peekRequest($freed[0]));
        $this->assertArrayHasKey($secondEffect, $container->get(DoctrineExecutionOperationStore::class)->pendingDeliveries($run, ''));
    }

    /** @param array<string, int|string> $source */
    private function acceptedSourceKey(array $source): string
    {
        return 'accepted-source:'.hash('sha256', serialize([$source['type'], $source['run_id'], $source['turn_no'], $source['step_id'], $source['attempt'], $source['idempotency_key']]));
    }
}
